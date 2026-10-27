"""Deploy Marked through SFTP, keeping application files outside the public root.

No remote shell, production database access, or production .env download is used.
"""
from __future__ import annotations

import errno
import io
import os
from pathlib import Path
import posixpath
import stat
import sys
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP_LINE = "require dirname(__DIR__) . '/app/bootstrap.php';"


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise ValueError(f"Missing deployment setting: {name}")
    return value


def clean_path(value: str) -> str:
    if not value or any(ord(c) < 32 for c in value) or "\\" in value:
        raise ValueError("Deployment paths must be nonempty POSIX directory paths.")
    return posixpath.normpath(value)


def validate_layout(public: str, private: str) -> None:
    if not public.startswith("/") or not private.startswith("/"):
        raise ValueError("Resolved deployment paths must be absolute.")
    common = posixpath.commonpath([public, private])
    if common in (public, private):
        raise ValueError("Public and private directories must be separate, non-nested directories. If SFTP is jailed to the public root, this layout needs access to a private directory first.")


def deployed_index(source: str, public: str, private: str) -> bytes:
    validate_layout(public, private)
    if source.count(BOOTSTRAP_LINE) != 1:
        raise ValueError("The public entry point changed; update the deployment packager.")
    relative = posixpath.relpath(private, public).replace("'", "\\'")
    replacement = (
        f"$markedPrivate = __DIR__ . '/{relative}';\n"
        "if (!is_file($markedPrivate . '/.env')) {\n"
        "    http_response_code(503);\n"
        "    header('Content-Type: text/plain; charset=utf-8');\n"
        "    header('Cache-Control: no-store');\n"
        "    exit('Marked er snart klar. Oppsettet er ikkje fullført enno.');\n"
        "}\n"
        "require $markedPrivate . '/app/bootstrap.php';"
    )
    return source.replace(BOOTSTRAP_LINE, replacement).encode()


def private_files(root: Path = ROOT) -> list[tuple[str, bytes]]:
    paths = sorted((root / "app").rglob("*.php"))
    paths += [root / "bin/console.php", root / "database/schema.sql"]
    result = []
    for path in paths:
        if path.is_symlink():
            raise ValueError("Deployment sources cannot be symbolic links.")
        result.append((path.relative_to(root).as_posix(), path.read_bytes()))
    result.append((".env.example", (root / "deploy/production.env.example").read_bytes()))
    return result


def public_files(public: str, private: str, root: Path = ROOT) -> list[tuple[str, bytes]]:
    result = []
    for path in sorted((root / "public").rglob("*")):
        if path.is_symlink():
            raise ValueError("Deployment sources cannot be symbolic links.")
        if not path.is_file() or path.name == "index.php":
            continue
        result.append((path.relative_to(root / "public").as_posix(), path.read_bytes()))
    # Switch the public entry point only after application code and assets arrive.
    result.append(("index.php", deployed_index((root / "public/index.php").read_text(), public, private)))
    return result


def exists(sftp, path: str) -> bool:
    try:
        sftp.stat(path)
        return True
    except OSError as error:
        if error.errno == errno.ENOENT:
            return False
        raise


def mkdirs(sftp, path: str, mode: int) -> None:
    if exists(sftp, path):
        if not stat.S_ISDIR(sftp.stat(path).st_mode):
            raise ValueError("A deployment directory is occupied by a file.")
        return
    parent = posixpath.dirname(path)
    if parent != path:
        mkdirs(sftp, parent, mode)
    sftp.mkdir(path, mode=mode)


def put_atomic(sftp, destination: str, data: bytes, mode: int) -> None:
    temporary = destination + ".marked-" + uuid.uuid4().hex + ".tmp"
    try:
        sftp.putfo(io.BytesIO(data), temporary, file_size=len(data))
        sftp.chmod(temporary, mode)
        # OpenSSH's atomic-overwrite extension avoids truncated live PHP files.
        sftp.posix_rename(temporary, destination)
    finally:
        if exists(sftp, temporary):
            sftp.remove(temporary)


def deploy(sftp, public_setting: str, private_setting: str = "") -> tuple[int, bool]:
    # Canonicalize existing parents so symlink aliases cannot put private files
    # inside the public root. Relative settings are relative to the SFTP home.
    public = clean_path(sftp.normalize(clean_path(public_setting)))
    if not stat.S_ISDIR(sftp.stat(public).st_mode):
        raise ValueError("ONECOM_FILE_DIR must be an existing public directory.")
    private_requested = clean_path(private_setting) if private_setting else posixpath.join(posixpath.dirname(public), "marked-private")
    parent = clean_path(sftp.normalize(posixpath.dirname(private_requested)))
    private = posixpath.join(parent, posixpath.basename(private_requested))
    if exists(sftp, private):
        private = clean_path(sftp.normalize(private))
    validate_layout(public, private)
    private_manifest = private_files()
    public_manifest = public_files(public, private)
    mkdirs(sftp, private, 0o750)
    for directory in ["storage/uploads", "storage/logs"]:
        mkdirs(sftp, posixpath.join(private, directory), 0o750)
    count = 0
    for base, manifest, mode, directory_mode in [(private, private_manifest, 0o640, 0o750), (public, public_manifest, 0o644, 0o755)]:
        for relative, data in manifest:
            destination = posixpath.join(base, relative)
            mkdirs(sftp, posixpath.dirname(destination), directory_mode)
            put_atomic(sftp, destination, data, mode)
            count += 1
    # Never read, overwrite, or delete the production .env or uploaded media.
    return count, exists(sftp, posixpath.join(private, ".env"))


def main() -> int:
    import paramiko

    host = required("ONECOM_SFTP_HOST")
    username = required("ONECOM_SFTP_USER")
    public = required("ONECOM_FILE_DIR")
    known_hosts = required("ONECOM_KNOWN_HOSTS")
    key_text = os.environ.get("ONECOM_SSH_KEY", "").strip()
    password = os.environ.get("ONECOM_SFTP_PASSWORD", "") or None
    if not key_text and not password:
        raise ValueError("Set ONECOM_SSH_KEY or ONECOM_SFTP_PASSWORD.")
    with tempfile.TemporaryDirectory(prefix="marked-sftp-") as temporary:
        hosts_path = Path(temporary) / "known_hosts"
        hosts_path.write_text(known_hosts + "\n")
        client = paramiko.SSHClient()
        client.load_host_keys(str(hosts_path))
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        key = None
        if key_text:
            key_path = Path(temporary) / "id_deploy"
            key_path.write_text(key_text + "\n")
            key_path.chmod(0o600)
            key = paramiko.PKey.from_path(key_path)
        try:
            client.connect(host, port=int(os.environ.get("ONECOM_SFTP_PORT", "22")), username=username, password=password, pkey=key, allow_agent=False, look_for_keys=False, timeout=30, auth_timeout=30, banner_timeout=30)
            with client.open_sftp() as sftp:
                count, configured = deploy(sftp, public, os.environ.get("ONECOM_PRIVATE_DIR", "").strip())
            print(f"Uploaded {count} application/public files. Existing configuration and uploads were preserved.")
            if not configured:
                print("Initial setup: create .env beside the uploaded private .env.example, install the database schema, and configure mail jobs. The website returns HTTP 503 until .env exists.")
            else:
                print("Production .env exists. Database migrations and scheduled jobs are managed separately on the host.")
        finally:
            client.close()
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as error:
        # Connection exceptions may contain remote paths. GitHub masks supplied
        # secrets, and no credentials or private-key material are printed here.
        print(f"Deployment failed: {error}", file=sys.stderr)
        sys.exit(1)

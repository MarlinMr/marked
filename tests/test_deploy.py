"""Deployment boundary and file-preservation checks; no hosting credentials needed."""
import errno
import os
from pathlib import Path
import tempfile
import unittest

from deploy import onecom


class LocalSFTP:
    """Filesystem-backed SFTP stand-in, including canonical paths and atomic writes."""

    def __init__(self, root):
        self.root = Path(root)
        self.uploaded = []

    def local(self, remote):
        return self.root / remote.lstrip("/")

    def normalize(self, remote):
        path = self.local(remote)
        if not path.exists():
            raise FileNotFoundError(errno.ENOENT, "No such directory")
        return "/" + path.resolve().relative_to(self.root).as_posix().removeprefix(".")

    def stat(self, remote):
        return self.local(remote).stat()

    def mkdir(self, remote, mode):
        self.local(remote).mkdir(mode=mode)

    def putfo(self, source, remote, file_size):
        self.local(remote).write_bytes(source.read())

    def chmod(self, remote, mode):
        self.local(remote).chmod(mode)

    def posix_rename(self, source, destination):
        os.replace(self.local(source), self.local(destination))
        self.uploaded.append(destination)

    def remove(self, remote):
        self.local(remote).unlink()


class DeployTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.sftp = LocalSFTP(self.temp.name)
        self.public = "/account/public_html"
        self.private = "/account/marked-private"
        self.sftp.local(self.public).mkdir(parents=True)

    def test_new_install_splits_public_and_private_files(self):
        count, configured = onecom.deploy(self.sftp, self.public)
        self.assertGreater(count, 10)
        self.assertFalse(configured)
        public = self.sftp.local(self.public)
        private = self.sftp.local(self.private)
        self.assertTrue((public / ".htaccess").is_file())
        self.assertTrue((public / "assets/style.css").is_file())
        self.assertTrue((private / "app/bootstrap.php").is_file())
        self.assertTrue((private / "database/schema.sql").is_file())
        self.assertTrue((private / "storage/uploads").is_dir())
        self.assertIn("APP_ENV=production", (private / ".env.example").read_text())
        self.assertFalse((private / ".env").exists())
        self.assertFalse((private / "database/seed.php").exists())
        self.assertFalse((public / "app").exists())
        self.assertFalse((public / ".env").exists())
        self.assertEqual(self.sftp.uploaded[-1], self.public + "/index.php")
        index = (public / "index.php").read_text()
        self.assertIn("__DIR__ . '/../marked-private'", index)
        self.assertIn("http_response_code(503)", index)
        self.assertFalse(list(Path(self.temp.name).rglob("*.tmp")))

    def test_redeployment_preserves_configuration_uploads_and_unmanaged_files(self):
        onecom.deploy(self.sftp, self.public)
        private = self.sftp.local(self.private)
        env = private / ".env"
        env.write_text("APP_ENV=production\nDB_PASSWORD=keep-me\n")
        image = private / "storage/uploads/user-photo.jpg"
        image.write_bytes(b"keep this upload")
        unrelated = self.sftp.local(self.public) / "existing-file.txt"
        unrelated.write_text("keep existing content")
        _, configured = onecom.deploy(self.sftp, self.public)
        self.assertTrue(configured)
        self.assertIn("DB_PASSWORD=keep-me", env.read_text())
        self.assertEqual(image.read_bytes(), b"keep this upload")
        self.assertEqual(unrelated.read_text(), "keep existing content")

    def test_rejects_private_directory_inside_public_root(self):
        with self.assertRaisesRegex(ValueError, "non-nested"):
            onecom.deploy(self.sftp, self.public, self.public + "/private")
        self.assertEqual(self.sftp.uploaded, [])

    def test_rejects_sftp_jail_at_public_root(self):
        with self.assertRaisesRegex(ValueError, "jailed"):
            onecom.deploy(self.sftp, "/")
        self.assertEqual(self.sftp.uploaded, [])

    def test_rejects_private_symlink_into_public_root(self):
        self.sftp.local(self.private).symlink_to(self.sftp.local(self.public), target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "non-nested"):
            onecom.deploy(self.sftp, self.public)
        self.assertEqual(self.sftp.uploaded, [])

    def test_explicit_private_location_and_php_quote_escaping(self):
        private = "/account/private folder's"
        onecom.deploy(self.sftp, self.public, private)
        index = (self.sftp.local(self.public) / "index.php").read_text()
        self.assertIn("../private folder\\'s", index)

    def test_public_manifest_does_not_include_private_sources(self):
        names = {name for name, _ in onecom.public_files(self.public, self.private)}
        self.assertEqual(names, {".htaccess", "index.php", "assets/style.css", "assets/app.js"})

    def test_atomic_write_failure_preserves_original_and_removes_temporary(self):
        destination = self.public + "/index.php"
        self.sftp.local(destination).write_bytes(b"previous entry point")
        def fail(source, target):
            raise OSError("Server does not support atomic rename")
        self.sftp.posix_rename = fail
        with self.assertRaises(OSError):
            onecom.put_atomic(self.sftp, destination, b"replacement", 0o644)
        self.assertEqual(self.sftp.local(destination).read_bytes(), b"previous entry point")
        self.assertFalse(list(Path(self.temp.name).rglob("*.tmp")))


if __name__ == "__main__":
    unittest.main()

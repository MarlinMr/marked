"""Deployment boundary and file-preservation checks; no hosting credentials needed."""
import errno
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import urllib.parse

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

    def test_requires_site_url_for_private_directory_inside_public_root(self):
        with self.assertRaisesRegex(ValueError, "ONECOM_SITE_URL"):
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

    def nested_probe(self, url):
        relative = urllib.parse.unquote(urllib.parse.urlsplit(url).path).lstrip('/')
        if relative.startswith('_marked/'):
            htaccess = self.sftp.local(self.public + '/_marked/.htaccess')
            self.assertEqual(htaccess.read_bytes(), b'Require all denied\n')
            # Protection must be checked before application files are uploaded.
            self.assertFalse((htaccess.parent / 'app/bootstrap.php').exists())
            return 403, b'Forbidden'
        return 200, self.sftp.local(self.public + '/' + relative).read_bytes()

    def test_protected_nested_deployment_verifies_denial_before_uploading(self):
        private = self.public + '/_marked'
        with mock.patch.object(onecom, 'http_probe', side_effect=self.nested_probe) as probe:
            _, configured = onecom.deploy(self.sftp, self.public, private, 'https://marked.example.test')
        self.assertFalse(configured)
        self.assertEqual(probe.call_count, 2)
        self.assertTrue(self.sftp.local(private + '/app/bootstrap.php').is_file())
        index = self.sftp.local(self.public + '/index.php').read_text()
        self.assertIn("__DIR__ . '/_marked'", index)
        self.assertFalse(list(Path(self.temp.name).rglob('marked-check-*')))

    def test_denial_failure_aborts_before_application_upload_and_cleans_probes(self):
        def exposed_probe(url):
            relative = urllib.parse.unquote(urllib.parse.urlsplit(url).path).lstrip('/')
            return 200, self.sftp.local(self.public + '/' + relative).read_bytes()
        private = self.public + '/_marked'
        with mock.patch.object(onecom, 'http_probe', side_effect=exposed_probe):
            with self.assertRaisesRegex(ValueError, 'not 403'):
                onecom.deploy(self.sftp, self.public, private, 'https://marked.example.test')
        self.assertFalse(self.sftp.local(private + '/app').exists())
        self.assertFalse(self.sftp.local(private + '/.env.example').exists())
        self.assertFalse(self.sftp.local(self.public + '/index.php').exists())
        self.assertFalse(list(Path(self.temp.name).rglob('marked-check-*')))
        self.assertTrue(self.sftp.local(private + '/.htaccess').is_file())

    def test_wrong_site_or_redirect_aborts_before_private_upload(self):
        for status, body in [(200, b'wrong site'), (302, b'redirect'), (403, b'Forbidden')]:
            with self.subTest(status=status, body=body):
                with mock.patch.object(onecom, 'http_probe', return_value=(status, body)):
                    with self.assertRaisesRegex(ValueError, 'ONECOM_SITE_URL'):
                        onecom.deploy(self.sftp, self.public, self.public + '/_marked', 'https://marked.example.test')
        self.assertFalse(self.sftp.local(self.public + '/_marked/app').exists())
        self.assertFalse(list(Path(self.temp.name).rglob('marked-check-*')))

    def test_nested_deployment_cannot_use_public_root_or_ancestor_as_private(self):
        for private in [self.public, '/account']:
            with self.assertRaisesRegex(ValueError, 'non-nested'):
                onecom.deploy(self.sftp, self.public, private, 'https://marked.example.test')

    def test_site_url_requires_https_origin_except_local_tests(self):
        for url in ['', 'http://marked.example.test', 'https://marked.example.test/path', 'https://marked.example.test?query=1', 'https://user:password@marked.example.test']:
            with self.assertRaises(ValueError):
                onecom.site_origin(url)
        self.assertEqual(onecom.site_origin('https://marked.example.test/'), 'https://marked.example.test')


if __name__ == "__main__":
    unittest.main()

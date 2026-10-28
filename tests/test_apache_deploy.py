"""Check real Apache HTTP denial alongside PHP filesystem access in Docker."""
import json
import os
from pathlib import Path
import tempfile
import unittest
import urllib.error
import urllib.request
import uuid

from deploy import onecom
from test_deploy import LocalSFTP


@unittest.skipUnless(os.environ.get('MARKED_TEST_URL'), 'Set MARKED_TEST_URL to the local Docker site.')
class ApacheDeploymentTests(unittest.TestCase):
    def test_http_denial_does_not_block_php_includes(self):
        origin = os.environ['MARKED_TEST_URL'].rstrip('/')
        public = onecom.ROOT / 'public'
        temporary = tempfile.TemporaryDirectory(prefix='_marked-test-', dir=public)
        self.addCleanup(temporary.cleanup)
        private = Path(temporary.name)
        private.chmod(0o755)
        (private / '.htaccess').write_bytes((onecom.ROOT / 'deploy/private.htaccess').read_bytes())
        (private / '.env').write_text('DUMMY_TEST_VALUE=not-a-secret\n')
        token = uuid.uuid4().hex
        (private / 'bootstrap.php').write_text("<?php return '" + token + "';\n")
        (private / 'fixture.txt').write_text(token)
        entry = public / ('marked-include-check-' + token + '.php')
        self.addCleanup(entry.unlink)
        entry.write_text("<?php header('Content-Type: application/json'); echo json_encode(['env_readable'=>is_readable(__DIR__ . '/" + private.name + "/.env'), 'included'=>require __DIR__ . '/" + private.name + "/bootstrap.php']);\n")

        onecom.verify_private_http(LocalSFTP(onecom.ROOT), '/public', '/public/' + private.name, origin)
        for filename in ['.env', 'bootstrap.php', 'fixture.txt']:
            with self.assertRaises(urllib.error.HTTPError) as caught:
                urllib.request.urlopen(origin + '/' + private.name + '/' + filename, timeout=10)
            self.assertEqual(caught.exception.code, 403)
        with urllib.request.urlopen(origin + '/' + entry.name, timeout=10) as response:
            data = json.load(response)
        self.assertTrue(data['env_readable'])
        self.assertEqual(data['included'], token)


if __name__ == '__main__':
    unittest.main()

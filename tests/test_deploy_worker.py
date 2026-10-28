"""Remote worker path validation, shell quoting, and failure propagation."""
from contextlib import nullcontext, redirect_stdout, redirect_stderr
import io
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest

from deploy import worker
from test_deploy import LocalSFTP


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.sftp = LocalSFTP(self.temporary.name)
        self.public = '/account/public_html'
        self.private = self.public + '/_marked'
        installed = self.sftp.local(self.private)
        (installed / 'bin').mkdir(parents=True)
        (installed / 'bin/console.php').write_text('<?php')
        (installed / '.env').write_text('Never download these credentials.')

    def client(self, exit_status=0):
        stdout = io.BytesIO('2 e-postar sende.\n'.encode())
        stdout.channel = SimpleNamespace(recv_exit_status=lambda: exit_status)
        stderr = io.BytesIO(b'failure\n' if exit_status else b'')
        commands = []
        def execute(command, timeout):
            commands.append((command, timeout))
            return None, stdout, stderr
        return SimpleNamespace(open_sftp=lambda: nullcontext(self.sftp), exec_command=execute), commands

    def test_existing_install_runs_digest_then_mail_without_reading_configuration(self):
        client, commands = self.client()
        with redirect_stdout(io.StringIO()):
            result = worker.run_worker(client, self.public, self.private)
        self.assertEqual(result, 0)
        command, timeout = commands[0]
        self.assertEqual(timeout, 300)
        self.assertEqual(shlex.split(command), ['php', self.private + '/bin/console.php', 'digest', '&&', 'php', self.private + '/bin/console.php', 'mail'])

    def test_missing_configuration_does_not_execute_any_remote_command(self):
        self.sftp.local(self.private + '/.env').unlink()
        client, commands = self.client()
        with self.assertRaisesRegex(ValueError, 'production .env'):
            worker.run_worker(client, self.public, self.private)
        self.assertEqual(commands, [])

    def test_remote_failure_reaches_the_workflow(self):
        client, commands = self.client(exit_status=1)
        with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            self.assertEqual(worker.run_worker(client, self.public, self.private), 1)
        self.assertEqual(len(commands), 1)

    def test_private_directory_cannot_replace_the_public_root(self):
        client, commands = self.client()
        with self.assertRaises(ValueError):
            worker.run_worker(client, self.public, self.public)
        self.assertEqual(commands, [])

    def test_command_handles_spaces_and_quotes_in_actual_shell(self):
        private = Path(self.temporary.name) / "application folder's"
        (private / 'bin').mkdir(parents=True)
        (private / 'bin/console.php').write_text('import sys\nprint(sys.argv[1])\n')
        result = subprocess.run(worker.worker_command(str(private), sys.executable), shell=True, capture_output=True, text=True, check=True)
        self.assertEqual(result.stdout.splitlines(), ['digest', 'mail'])

    def test_failed_digest_stops_the_command_and_returns_failure(self):
        private = Path(self.temporary.name) / 'failed-worker'
        (private / 'bin').mkdir(parents=True)
        (private / 'bin/console.php').write_text("import sys\nprint(sys.argv[1])\nsys.exit(3 if sys.argv[1] == 'digest' else 0)\n")
        result = subprocess.run(worker.worker_command(str(private), sys.executable), shell=True, capture_output=True, text=True)
        self.assertEqual(result.returncode, 3)
        self.assertEqual(result.stdout.splitlines(), ['digest'])


if __name__ == '__main__':
    unittest.main()

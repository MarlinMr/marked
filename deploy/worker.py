"""Run the installed mail/digest commands over SSH; credentials stay on the host."""
import os
import posixpath
import shlex
import sys

from deploy.onecom import exists, required, resolve_paths, ssh_connection, validate_layout


def worker_command(private: str, php: str = 'php') -> str:
    console = shlex.quote(posixpath.join(private, 'bin/console.php'))
    php = shlex.quote(php)
    # Deliver new digest messages in the same run instead of waiting five minutes.
    return f'{php} {console} digest && {php} {console} mail'


def run_worker(client, public_setting: str, private_setting: str = '', php: str = 'php') -> int:
    with client.open_sftp() as sftp:
        public, private = resolve_paths(sftp, public_setting, private_setting)
        validate_layout(public, private, allow_nested=True)
        for relative in ['bin/console.php', '.env']:
            if not exists(sftp, posixpath.join(private, relative)):
                raise ValueError('The installed console or production .env is missing from ONECOM_PRIVATE_DIR.')
    _, stdout, stderr = client.exec_command(worker_command(private, php), timeout=300)
    output = stdout.read().decode('utf-8', errors='replace')
    errors = stderr.read().decode('utf-8', errors='replace')
    if output:
        print(output, end='')
    if errors:
        print(errors, end='', file=sys.stderr)
    return stdout.channel.recv_exit_status()


def main() -> int:
    public = required('ONECOM_FILE_DIR')
    with ssh_connection() as client:
        return run_worker(client, public, os.environ.get('ONECOM_PRIVATE_DIR', '').strip(), os.environ.get('ONECOM_PHP_BIN', 'php').strip() or 'php')


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(f'Mail worker failed: {error}', file=sys.stderr)
        sys.exit(1)

#!/usr/bin/env python3
"""Deploy only Objects on Purpose printer code. Never overwrite live credentials or queue data."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import shlex
import subprocess
import tarfile
import tempfile


SOURCE = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host', required=True, help='Dedicated Objects on Purpose SSH user@host')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_-]+@[A-Za-z0-9.-]+', args.host):
        parser.error('Expected a dedicated SSH user@host without shell options.')
    # Deployment must never accidentally target the former personal-site service.
    config = json.loads((SOURCE / 'hosting.json').read_text())
    if args.host != config['sshDestination']:
        parser.error('Destination does not match this project\'s independent hosting configuration.')
    subprocess.run(['php', SOURCE / 'tests/relay-status-test.php'], check=True)
    subprocess.run(['php', SOURCE / 'tests/relay-raster-stream-test.php'], check=True)
    for php in sorted((SOURCE / 'app').glob('*.php')):
        subprocess.run(['php', '-l', php], check=True, stdout=subprocess.DEVNULL)
    release = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
    remote = '/home/protected/releases/' + release
    ssh_args = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', args.host]

    def ssh(command, **kwargs):
        return subprocess.run(ssh_args + [command], check=True, timeout=90, **kwargs)

    ssh('mkdir -p ' + shlex.quote(remote), capture_output=True)
    with tempfile.TemporaryDirectory(prefix='oop-printer-deploy-') as tmp:
        archive = Path(tmp) / 'code.tar'
        manifest = {}
        for base in ['app', 'public']:
            for file in sorted((SOURCE / base).rglob('*')):
                if file.is_file():
                    manifest[str(file.relative_to(SOURCE))] = hashlib.sha256(file.read_bytes()).hexdigest()
        with tarfile.open(archive, 'w') as tar:
            for base in ['app', 'public']:
                tar.add(SOURCE / base, arcname=base)
        with archive.open('rb') as incoming:
            ssh('tar -xf - -C ' + shlex.quote(remote), stdin=incoming, capture_output=True)
        verify = '''
<?php
$root=$argv[1]; $manifest=json_decode(stream_get_contents(STDIN),true);
foreach($manifest as $name=>$expected) {
  if (!hash_equals($expected,hash_file('sha256',$root.'/'.$name))) {fwrite(STDERR,'Uploaded code mismatch.');exit(1);}
}
echo "Uploaded code matches source.\\n";
'''
        # The manifest is code-only; password hashes, device token and queue never enter it.
        checkfile = Path(tmp) / 'check.php'
        checkfile.write_text(verify.lstrip())
        subprocess.run(['scp', '-q', '-o', 'BatchMode=yes', checkfile, args.host + ':' + remote + '/check.php'], check=True, timeout=90)
        ssh('php ' + remote + '/check.php ' + remote, input=json.dumps(manifest).encode())
    # /home/protected/app is an atomic link to immutable code. Runtime files live alongside it.
    command = f'''set -eu
test -s /home/protected/printer-password.hash
test -s /home/protected/printer-device-token
test -s /home/protected/printer-relay.json
test -d /home/protected/printer-sessions
for file in {remote}/app/*.php; do php -l "$file" >/dev/null; done
chgrp -R web {remote}
find {remote} -type d -exec chmod 750 {{}} +
find {remote} -type f -exec chmod 640 {{}} +
if [ -L /home/protected/app ]; then readlink /home/protected/app > {remote}/previous-app; fi
if [ -e /home/protected/app ] && [ ! -L /home/protected/app ]; then exit 1; fi
ln -s {remote}/app /home/protected/app.new
php -r 'if (!rename("/home/protected/app.new", "/home/protected/app")) exit(1);' 
if [ -f /home/public/.htaccess ]; then cp -p /home/public/.htaccess {remote}/previous.htaccess; fi
if [ -f /home/public/index.php ]; then cp -p /home/public/index.php {remote}/previous-index.php; fi
cp {remote}/public/index.php /home/public/index.php.new
chmod 644 /home/public/index.php.new
mv -f /home/public/index.php.new /home/public/index.php
cp {remote}/public/.htaccess /home/public/.htaccess.new
chmod 644 /home/public/.htaccess.new
mv -f /home/public/.htaccess.new /home/public/.htaccess
'''
    ssh(command, capture_output=True)
    print(json.dumps({'release': remote, 'filesVerified': len(manifest), 'runtimeFiles': 'untouched'}))


if __name__ == '__main__':
    main()

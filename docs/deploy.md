# Deploying to the live server

A merge to `main` puts the plugin on the server by itself, once the
`Pull Request Quality` workflow has gone green for that merge. Nothing happens
until the secrets below exist: without them the job writes a note saying what
is missing and stops, so merges stay green while this is still being set up.

The same arrangement exists in the theme repository, pointing at its own
folder.

## The secrets to add

Repository → Settings → Secrets and variables → Actions → New repository secret.

| Secret | What it is | Required |
| --- | --- | --- |
| `SFTP_HOST` | The server's host name, e.g. `ssh.hosting.nl` | yes |
| `SFTP_USER` | The SFTP user | yes |
| `SFTP_PATH` | The plugin folder on the server. **Must end in `wp-content/plugins/cjw`** | yes |
| `SFTP_KEY` | A private SSH key, the whole file including its header and footer lines | one of these two |
| `SFTP_PASSWORD` | The SFTP password | one of these two |
| `SFTP_PORT` | Only when the server does not use 22 | no |
| `SFTP_KNOWN_HOSTS` | The server's public key, so its identity is verified | no, but see below |

Prefer `SFTP_KEY` over `SFTP_PASSWORD`. A key can be given to this repository
and to nothing else, and it can be withdrawn without changing the password a
person uses.

```bash
# A key for this purpose only. Give the .pub half to the hosting party and
# paste the private half into SFTP_KEY.
ssh-keygen -t ed25519 -C "github-actions deploy" -f ./cjw-deploy -N ""

# The server's own identity, for SFTP_KNOWN_HOSTS.
ssh-keyscan -p 22 ssh.hosting.nl
```

Without `SFTP_KNOWN_HOSTS` the deploy still runs, and warns on every run: an
unverified host key means the first connection cannot be told apart from
somebody standing in the middle of it.

## What gets uploaded

`git archive` of the merge commit, minus the development files — the same set
the `npm run bundle` zip contains. Anything gitignored, which is to say
`vendor/`, `node_modules/` and every cache, cannot reach the server even by
accident, because it was never in the archive.

The upload mirrors: a file deleted from the repository is deleted from the
server. That is what makes an old PHP file stop being reachable, and it is also
the reason for the two guards below.

## The two guards

**The path.** `SFTP_PATH` has to end in `wp-content/plugins/cjw`. A typo that
pointed the mirror at the document root would otherwise delete a website.

**The folder.** Before uploading, the job lists the remote folder. It proceeds
if the folder is empty, or if it already contains `cjw.php`. Anything else
stops the run rather than emptying it.

## Trying it before trusting it

Actions → Deploy → Run workflow. The `Only report what would change` box is
ticked by default: lftp lists every file it would send or delete and uploads
nothing. Run that first, read the list, and only then untick it.

## Turning merges into approvals

The job runs in an environment called `production`. Add required reviewers to
it under Settings → Environments → production, and every deploy waits for
somebody to press a button. Nothing in the workflow file needs to change.

## When something goes wrong

The plugin directory is replaced, not the database, so putting a previous
version back is a matter of re-running the deploy from an earlier commit:
Actions → Deploy → Run workflow does the current `main`, so revert the merge
and let it deploy that.

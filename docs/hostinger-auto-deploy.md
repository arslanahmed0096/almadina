# Hostinger Auto Deploy

This repository includes a GitHub Actions workflow at `.github/workflows/deploy-hostinger.yml`.

## What it does

- Deploys on every push to `main`
- Uploads the Laravel project directly to your Hostinger target directory
- Keeps the server `.env`, logs, cache data, and uploaded files untouched
- Rebuilds Laravel caches after deployment

## Current server layout

Your current Hostinger setup keeps the full Laravel project directly inside `public_html`.

Because of that, this workflow now deploys to one single path.

## GitHub secrets to add

Go to `GitHub -> Repository -> Settings -> Secrets and variables -> Actions`.

Add these **repository secrets**:

- `HOSTINGER_HOST`
- `HOSTINGER_PORT`
- `HOSTINGER_USERNAME`
- `HOSTINGER_SSH_PRIVATE_KEY`
- `HOSTINGER_APP_PATH`

Backward-compatible:

- `HOSTINGER_SSH_KEY`
  - Old name still works, but `HOSTINGER_SSH_PRIVATE_KEY` is clearer and recommended

Optional **repository variable**:

- `HOSTINGER_RUN_MIGRATIONS`
  - Set to `true` only if you want `php artisan migrate --force` on each deploy

## Secret values

Use your Hostinger SSH values for:

- `HOSTINGER_HOST`: server IP or host
- `HOSTINGER_PORT`: SSH port
- `HOSTINGER_USERNAME`: SSH username
- `HOSTINGER_SSH_PRIVATE_KEY`: private SSH key content

Example paths:

- `HOSTINGER_APP_PATH`: `/home/u587761516/domains/your-domain.com/laravel-app`

For your current server, use:

- `HOSTINGER_APP_PATH`: `/home/u587761516/domains/al-madinaelectronics.com/public_html`

## First-time server setup

1. Generate an SSH key pair on your computer.
2. Add the **public** key in Hostinger SSH keys.
3. Put the **private** key in GitHub secret `HOSTINGER_SSH_PRIVATE_KEY`.
4. Make sure your already-working Laravel app exists on Hostinger.
5. Confirm `.env` is present on the server inside `HOSTINGER_APP_PATH`.
6. Confirm `vendor/` already exists on the server from your successful manual setup.

## Important note about your layout

Your full Laravel app is currently inside `public_html`.

That is why this workflow:

- deploys the whole project to `HOSTINGER_APP_PATH`
- does not overwrite `.env`
- does not overwrite `vendor`
- keeps runtime storage data out of sync deletion

## Important SSH rule

You should not need to change the key on every deployment.

- Hostinger stores the **public** key once in hPanel
- GitHub stores the matching **private** key once in repository secrets
- Every deployment reuses that same key pair

If deploys keep failing and you feel like it is asking again, the usual cause is one of these:

- The GitHub secret contains the **public** key instead of the **private** key
- The private key was pasted with broken line breaks
- The public key currently added in Hostinger does not match the private key in GitHub
- The key is passphrase-protected, which GitHub Actions cannot answer interactively

## Correct key format

Your GitHub secret must contain the full private key block, for example:

```text
-----BEGIN OPENSSH PRIVATE KEY-----
...
-----END OPENSSH PRIVATE KEY-----
```

Hostinger should receive the `.pub` file content, which starts more like:

```text
ssh-ed25519 AAAA...
```

## Important note about Composer packages

This workflow is designed around your current setup where the app is already running on Hostinger.

If you later change `composer.json`, SSH into Hostinger once and run Composer there so `vendor/` matches the new dependencies.

## Branch trigger

The workflow currently deploys when you push to `main`.

If your branch is different, update:

```yml
on:
  push:
    branches:
      - main
```

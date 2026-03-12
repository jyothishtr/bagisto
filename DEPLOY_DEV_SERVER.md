# Bagisto Dev Server Deploy (Linux + Git + Docker)

## 1. One-time server setup

```bash
sudo apt-get update
sudo apt-get install -y git docker.io docker-compose-plugin
sudo usermod -aG docker "$USER"
newgrp docker
```

## 2. Clone project

```bash
git clone <YOUR_GIT_REPO_URL> bagisto
cd bagisto
```

## 3. Create Docker env file

```bash
cp .env.docker.example .env.docker
```

Edit `.env.docker` and set:
- `APP_URL` to your server URL or IP, for example `http://192.168.1.50:8080`
- DB passwords to secure values

## 4. First deployment

```bash
./scripts/dev-server-deploy.sh
```

Then open:
- Shop: `http://YOUR_SERVER_IP:8080`
- Admin: `http://YOUR_SERVER_IP:8080/admin`

## 5. Update deployment (after new code push)

```bash
cd bagisto
git pull origin <YOUR_BRANCH>
./scripts/dev-server-deploy.sh
```

## 6. Useful commands

```bash
docker compose -f docker-compose.dev-server.yml --env-file .env.docker ps
docker compose -f docker-compose.dev-server.yml --env-file .env.docker logs -f web
docker compose -f docker-compose.dev-server.yml --env-file .env.docker logs -f app
docker compose -f docker-compose.dev-server.yml --env-file .env.docker down
```


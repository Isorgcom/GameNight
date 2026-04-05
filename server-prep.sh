#!/bin/bash
set -e

# --- Docker ---
echo "[1/3] Installing Docker..."
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -qq
apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable --now docker
echo "Docker $(docker --version) installed."

# --- Portainer ---
echo "[2/3] Starting Portainer..."
docker volume create portainer_data
docker run -d \
  --name portainer \
  --restart=always \
  -p 9000:9000 \
  -p 9443:9443 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v portainer_data:/data \
  portainer/portainer-ce:latest
echo "Portainer running on :9000 (HTTP) and :9443 (HTTPS)."

# --- Nginx Proxy Manager ---
echo "[3/3] Starting Nginx Proxy Manager..."
mkdir -p /opt/npm
cat > /opt/npm/docker-compose.yml <<'EOF'
services:
  npm:
    image: jc21/nginx-proxy-manager:latest
    container_name: nginx-proxy-manager
    restart: unless-stopped
    ports:
      - "80:80"
      - "81:81"
      - "443:443"
    volumes:
      - ./data:/data
      - ./letsencrypt:/etc/letsencrypt
EOF
docker compose -f /opt/npm/docker-compose.yml up -d
echo "Nginx Proxy Manager running."

SERVER_IP=$(curl -4 -sf https://ifconfig.me || hostname -I | awk '{print $1}')
echo ""
echo "Done. Summary:"
echo "  Portainer:           https://${SERVER_IP}:9443"
echo "  Nginx Proxy Manager: http://${SERVER_IP}:81"
echo "  NPM default login:   admin@example.com / changeme"

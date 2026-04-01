# GameNight

URL: http://GameNight.Isorg.Com

## Server

- Host: 198.46.254.149
- Deploy path: /root/docker/gamenight/
- Container name: gamenight

## Nginx Proxy Manager (NPM)

The container does not bind directly to a host port. Instead it exposes port 80
internally and connects to the `npm_default` Docker network, allowing NPM to
route traffic to it by container name.

NPM admin UI: http://198.46.254.149:81

Proxy Host settings:
- Domain: GameNight.Isorg.Com
- Scheme: http
- Forward Hostname: gamenight
- Forward Port: 80
- Block Common Exploits: on

## Deploy

Upload files (no rebuild needed for www changes):

    pscp -pw '<password>' -hostkey "SHA256:XWmvtZUDjB29O3+smO43o+crFmXzw9yGCa4fBDxtdDI" <file> root@198.46.254.149:/root/docker/gamenight/www/

Full rebuild:

    ssh root@198.46.254.149 'cd /root/docker/gamenight && docker compose up -d --build'

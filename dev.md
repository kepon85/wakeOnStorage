# Note dev

## API local

### Lancement

cd ~/dev/wakeOnStorage-local
php -S 0.0.0.0:8001 -t public/

### Test

# curl -s -X POST -H "Authorization: Bearer mysecrettoken" -H "Content-Length: 0" http://192.168.1.10:8001/?r=nas/down
# curl -s -X GET -H "Authorization: Bearer mysecrettoken" -H "Content-Length: 0" http://192.168.1.10:8001/?r=nas/status

## Interface distante

### Lancement

cd ~/dev/wakeOnStorage
php -S 0.0.0.0:8000 -t public
firefox http://192.168.1.10:8000

### Test



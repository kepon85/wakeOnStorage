Ce programme est écris en PHP (html, js, css), la base est soit du Mysql soit du sqlite. Si c'est justifier d'utiliser Laraval alors c'est possible. Mais pas un autre framework.

Un seul programme mais une une interface qu'on peut personnaliser (style css et js et action possible) elle est différente selon le virtualhost (domaine) par laquelle on l'affiche. On récupère un fichier de conf qui correspond au nom du domaine. On charge un autre thème (style) CSS si on le précise et un custom script JS (vide par défaut). 

Chaque interface possède un fichier de conf yaml du nom du virtualhost, je te met un brouillon de ce que ça peut être (possible de modifier si pas pratique) : 

```yaml
interface: # 
	name: Le NAS de cloudGirofle
	logo: chemin/vers/logo
	css: 
		- chemin/css
	js_include: 
		- chemin/js
auth: # Authentification pour accéder à l'interface/service
	method:
   		- [none|imap|file|uniq]
    uniq: 
    	password: PASSWORD
    	methode:
    	...
    imap:
    	server: imap.retzo.net
    	port: ...
    	secure: tls|ssl
    file: 
    	path: chemin/password...
router:  # Ce paramètre peut être commun (fichier de conf général mais il est surchargé si présent dans un "enfant" ici
	router_check: 
		methode: ping
			host: nas.retzo.net
			count: 1 	# Nombre de ping à testé
			timeout: 1      # Timeout en secondes pour le ping
	router_up : # Fréquence d'allumage du routeur (si éteint - commenté = toujours allumé)
		- 09:00
		- 12:00
energy: solar-stric|solar|all|solar-batterie	# Sur quel énergie on fonctionne, quel contrainte
	# - solar-strict : le storage ne peut être allumer que si la production solaire (prévision) est supérieur à la consommation électrique du storage. La limite en temps d'allumage est le couché de soleil...
	# - solar : on préfère le solair (on l'indique mais pas de contriante... on peut l'alluemr tout le temps en vrai)
	# - all: pas d'importance sur l'énergie
	# - solar-batterie : c'est solaaire + stockage sur batterie
	#		batterie: 0 #ID de la batterie 
	#		soc_min: 30% # % sous lequel on ne peut pas allumer le storage car batterie trop basse, sinon on affiche le % de batterie et la production solaire
storage:
	conso: 10 # Watt - consommation électrique
	wake_time: # Temps d'allumage proposé  (certain seront non disponible en "solar-stric" si le soleil se couche.. Exemple il est 17h, le soleil se couche à 19, je ne peu xpas choisir 3, 5, 8h)
		- 0.5 
		- 1
		- 3
		- 5
		- 8
    check: 
    	methode: port
    	param: 
    		host: nas.retzo.net
    		port: 22223
#		methode: api
#    	param: 			# Tu peux me faire d'autres proposition pour coller avec un maxium d'API
#    		url: "http://wakeonstorage-local-test.retzo.net/api/nas/status"	
#    		auth: 
# 				type: basic|bearer|none
#				username: 
#				password:
#				tocken: 
#			headers:
#				Content-Type: application/json
#				Content-Length: 0
#			method: POST|GET|PUT|...
#			expected_result:
#				status_code: 200             # on attend un 200 OK
	up: 
		methode: api
    	param: 			# Tu peux me faire d'autres proposition pour coller avec un maxium d'API
    		url: "http://wakeonstorage-local-test.retzo.net/api/nas/up"	
    		auth: 
 				type: bearer
				tocken: mysecrettoken
			headers:
				Content-Type: application/json
				Content-Length: 0
			method: POST
			expected_result:
				status_code: 200             # on attend un 200 OK
				json:						# On vérifie que {"success":true}
					path: "$.success"
					equals: "true"
#        methode: wakeonlan				# Autre méthode à implémenté.. le wakeonlan version "wan" je ne suis pas sûr des paramètres alors tu peux compléter/modifier
#			mac: "AA:BB:CC:DD:EE:FF"     // MAC de la cible
#			broadcastIp: "192.168.1.255"         // broadcast LAN
#			port: 9                       // port UDP
#			secureOnPassword: null                    // pas de SecureOn
#			targetHost: "mon.domaine.fr"         // mon IP publique ou DNS
    	time: 120 # Temps de démarrage attendu (après un up) en secondes
    	timeout: 300 # Temps après lequel on considère qu'il y a un problème
    	post:
    		methode: redirect-iframe|redirect	# Redirection dans l'Iframe ou redirection de toute la page 
    		page: https://nas.retzo.net
    down: 
		methode: api
    	param:
    		url: "http://wakeonstorage-local-test.retzo.net/api/nas/down"	
    		auth: 
 				type: bearer
				tocken: mysecrettoken
			headers:
				Content-Type: application/json
				Content-Length: 0
			method: POST
			expected_result:
				status_code: 200             # on attend un 200 OK
				json:						# On vérifie que {"success":true}
					path: "$.success"
					equals: "true"
        time: 120
    	timeout: 300
        post:
	    	methode: text
	    	content: "<p>Le Nas est disponible, vous pouvez vous y connecter.</p>"	# Affiche ce texte dans l'iframe/a la place de l'Iframe
```

On va prendre ici quelques exemples : 

* mediapaillourte.wos.retzo.net
  * Authentification  ! Mot de passe "unique" (sans login)
  * Contraint uniquement SI soleil, Si surplus (prévision) et s'allume uniquement quand la consommation n'éxcède pas la production (selon les prévisions)
  * Après allumage on affichera une page web en iframe qui prendra 2/3 de la page
* cloudgirofle-bkp.wos.retzo.net
  * Authentification : Utilisateur + mot de passe dans un fichier text
  * Sur cette interface : 
    * On affiche la production solaire ($production_solaire) si elle est connu, stocké depuis pas trop longtemps.. Sinon on affiche $production_solaire_estimation
      * Enfin on affiche le fait d'avoir ou non suffisamment d'électricité solaire pour allumer ce qu'il faut allumer  
  * On affiche la prod solaire, on en courage mais on contraint pas
  * Après allumage on affiche juste un mot pour dire que c'est up, on peut down 
* cpielgl-archive.wos.retzo.net
  * Authentification : IMAP + Utilisateur/Mot de passe dans un fichier text (on affiche qu'un seul formulaire d'authentification bien sûr)
  * On affiche la prod solaire, on en courage mais on contraint pas
* demo.wakeonstorage.retzo.net
  * Authentification : Aucune
  * On affiche la prod solaire, on en courage mais on contraint pas

Le fonctionnement de l'interface : 

* Si le routeur n'est pas jouaniable : on dit à quel heure il le sera normalement
  * Si on souhait planifier un allumage c'est possible (selon les contraintes de production solaire et les contraintes d'énergie spécifique à chaque internface) on indique une heure de fin (heure du début étant l'allumage planifier du routeur)
    * Un e-mail est envoyé (avec moi en copie caché systématique) :
      * Si la planification a fonctionne on envoi un e-mail pour dire que c'est jouaniable (fonction de ce qu'il y a dans up/post/methode) et on indique le temps 
      * Si la planification à échoué on envoi un e-mail pour le signaler en indiquer d'aller vers l'admin (adresse de contact) pour investigation...
* Si le routeur est jouaniable
  * Si le storage est joiniable (check status) 
    * On affiche direct le up/post (iframe ou message
    * On propose de l'éteindre (ne sera effectif que si personne n'est connecté) (lancer un "down") 
      * Si c'est ok, on affiche le down/post
  * Si le storage n'est pas jouaniable (check status)
    * On propose l'allumage (début maintenant, fin en fonction des contraintes d'énergie comme précisé plus haut avec le routeur) dans la conf global on peut avoir un paramètre pour annoncer la duré maximum d'allumage (Xh) et on fait un pas de 0.5, 1 2 3 4 5 6h... on peut avoir cette conf par interface qui est "surchargé" 
      * Si l'utilisateur allume alors en AJAX on demande le UP. On affiche de quoi patientez pendant ce temps... (paramètre time affiché)  on vérifie le "status" à interval régulier (défini dans le fichier de conf < paramètre à ajouter)
        * Si timeout atteint alors on affiche une erreur, on demande de contacter l'admin (contact global) pour résolution
        * Si status = ok, on fait le post/up
  * Bien sûr ce status est rafraichie en ajax toutes les X secondes (défini dans la configuration global)

D'autres informations : 

* Sur ces interfaces les données sont récupéré en Ajax sur un script qui s'occupe d'aller chercher les données, de les stocker, de gérer du cache éventuellement...
  * Des donnes 
    * $batterie[0] niveau batterie 0
      * curl -s -X GET   -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI0ZmE0Mzc5ODIxMzM0ZTk4OGQ0N2ZjMDQwMWI3OTNlNCIsImlhdCI6MTc1MjQ5NjcwOSwiZXhwIjoyMDY3ODU2NzA5fQ.GUxMTdSwtngTW0ZbOTBxSYAHpBp_6ewQl6am11MSvYk"   -H "Content-Type: application/json"   http://wakeonstorage-local.retzo.net:8123/api/states/sensor.batterie1
      * cache : oui
      * temps de vie de la donnée : 10m
      * l'API peut ne pas être disponible, si c'est le cas on indique NA
    * $production_solaire
      * curl -s -X GET   -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI0ZmE0Mzc5ODIxMzM0ZTk4OGQ0N2ZjMDQwMWI3OTNlNCIsImlhdCI6MTc1MjQ5NjcwOSwiZXhwIjoyMDY3ODU2NzA5fQ.GUxMTdSwtngTW0ZbOTBxSYAHpBp_6ewQl6am11MSvYk"   -H "Content-Type: application/json"   http://wakeonstorage-local.retzo.net:8123/api/states/sensor.puissance_corrigee_2  
      * cache : oui
      * temps de vie de la donnée : 10m
      * l'API peut ne pas être disponible, si c'est le cas on indique NA
    * $production_solaire_estimation
      * curl -s "https://api.solcast.com.au/rooftop_sites/264a-d2d7-20d7-66e5/forecasts?format=json"   -H "Authorization: Bearer WchAGalywMgBjtdubPqAA3ScVwCXTEPr"
        * exemple de résultat dans le dépôt : doc/exemple-prevision-forcasts-solcast.json
      * cache : oui
      * temps de vie de la donnée : 3h (on ne peut faire plus de 20 requête/j)

Bien sûr il y a un fichier de conf général avec : 

* contact_admin (nom, e-mail)
* Données global ($production_solaire_estimation, $production_solaire) (config des différentes API... temps de cache, )
* API, max_attempts, default_timeout... 
* Log (taille maxi, emplacement, niveau de log (4 niveau)...) en mode début on consigne TOUT action possible ...
* Paramètre SMTP pour l'envoi de mail
* Emplacement des config "par interface" 
  * Dans le git ignore on ignore toutes les configs des interfaces sauf celles nommé example*.yml

Aussi il faut  :

* Compter en base de donnée le nombre de UP/DOWN de chaque storage (garder trace) et QUI l'a allumé (IP, utilisateur s'il y a)
* probablement un cron pour exécuter le "spooler" des demande de UP planifié MAIS aussi des down qui ne sont pas (forcément) faite par l'utilisateur en direct...)
  * Bien sûr il faudra gérer les colision, si quelqu'un demande un up de 12h à 13h et un autre de 12h à 15h il ne faut pas lancer de down à 13h...

Côté interface utilisateur, on va utiliser bootstrap pour le CSS

* Je vois bien une bannière en haut qui occupe ~1/4 de la page 
  * Tout à gauche le logo (que tu peux trouvé dans doc/ sur le dépôt), ensuite les énerges (batterie, solaeil, prochain levé si c'est la nuit, prochain couché si c'est le jour (avec le nombre d'heure restante de soleil si en énergie solar*
  * Tout à droite un bouton Allumer durant (et là un select avec le nombre de temps de la conf, et un bouton éteindre (grisé ou non selon l'état) un bouton qui symbolise l'état
* La partie en dessous je sais trop comment l'habiller... Propostion de "storage" quand c'est du texte ça fait un gros "vide"... ? Quand c'est une iframe alors on occupe un max de place.

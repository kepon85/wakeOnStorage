# Étape 0 – Mise en place du socle

Ce dépôt contient un socle Laravel prêt à recevoir le code applicatif WakeOnStorage. Les dépendances de base (HTTP client, SDK TipiMail, export CSV/XLSX/PDF, librairie de graphiques JS) sont déclarées pour que `composer install` et `npm install` installent directement l'environnement requis.

## Prérequis
- PHP 8.2 ou supérieur avec les extensions courantes (OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, Fileinfo).
- Composer 2.
- Node.js 18+ et npm.
- Accès réseau aux dépôts Composer et npm pour télécharger les dépendances.

## Installation
1. **Cloner le dépôt**
   ```bash
   git clone <repo> wakeOnStorage
   cd wakeOnStorage
   ```

2. **Installer les dépendances PHP**
   ```bash
   composer install
   ```

3. **Installer les dépendances front** (Chart.js inclus)
   ```bash
   npm install
   ```

4. **Initialiser l'environnement**
   - Copier le fichier d'exemple :
     ```bash
     cp .env.example .env
     ```
   - Générer la clé applicative :
     ```bash
     php artisan key:generate
     ```

5. **Configurer TipiMail**
   - Renseigner les variables suivantes dans `.env` :
     ```env
     TIPIMAIL_API_USER="<votre_user>"
     TIPIMAIL_API_KEY="<votre_clef>"
     TIPIMAIL_API_URL="https://api.tipimail.com"
     TIPIMAIL_DEFAULT_FROM="no-reply@example.com"
     TIPIMAIL_DEFAULT_FROM_NAME="WakeOnStorage"
     ```
   - Les valeurs sont utilisées par `config/tipimailparser.php` et `config/services.php`.

6. **Lancer les outils de développement**
   - Démarrer le serveur HTTP :
     ```bash
     php artisan serve
     ```
   - Démarrer Vite en mode dev :
     ```bash
     npm run dev
     ```

## Arborescence
- `app/` : code applicatif Laravel (contrôleurs, middleware, providers...)
- `config/` : configuration de Laravel et des intégrations TipiMail/export.
- `resources/` : assets front (JS/Vite) et vues Blade.
- `routes/` : routes `web`, `api`, `console`, `channels`.
- `database/` : migrations, seeders, factories.
- `storage/` : logs et cache (non versionné hors fichiers vides de structure).
- `tests/` : tests unitaires et fonctionnels.

Le socle est maintenant prêt pour développer les fonctionnalités métier.

<?php
/*
|--------------------------------------------------------------------------
| config.example.php — Template de configuration
|--------------------------------------------------------------------------
| Copiez ce fichier en "config.php" et remplissez vos valeurs réelles.
| Ne committez jamais config.php dans Git.
|--------------------------------------------------------------------------
*/

// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'calendars_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// SMTP Gmail
// Pour le mot de passe : générez un "mot de passe d'application" dans
// votre compte Google > Sécurité > Connexion à Google > Mots de passe des applications
define('SMTP_HOST',         'smtp.gmail.com');
define('SMTP_PORT',         587);
define('SMTP_USERNAME',     'votre@gmail.com');
define('SMTP_APP_PASSWORD', 'votre_app_password_sans_espaces');
define('MAIL_FROM_NAME',    'Calendars App');

/*
|--------------------------------------------------------------------------
| URL de base de l'application
|--------------------------------------------------------------------------
| Utilisée pour construire les liens d'acceptation / refus dans les e-mails
| d'invitation. Doit pointer vers la racine du projet, sans slash final.
|
| Exemples :
|   Développement local : 'http://localhost/CALENDARS'
|   Production          : 'https://mondomaine.fr'
|--------------------------------------------------------------------------
*/
define('APP_URL', 'http://localhost/CALENDARS');

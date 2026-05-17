# CALENDARS

Application calendrier (PME) — FullCalendar + PHP + MySQL.

## Installation

### 1. Base de données

Créez la base et les tables en exécutant `schema.sql` :

```bash
mysql -u root -p < schema.sql
```

Ou importez le fichier depuis phpMyAdmin.

### 2. Configuration

Copiez le fichier exemple et remplissez vos valeurs :

```bash
cp config.example.php config.php
```

Éditez `config.php` avec :
- vos identifiants MySQL
- votre adresse Gmail et votre mot de passe d'application SMTP

> `config.php` ne doit **jamais** être commité dans Git.

### 3. Lancement

Placez le dossier dans `C:\wamp64\www\CALENDARS` puis ouvrez :

```
http://localhost/CALENDARS/dev_login.php
```

## Fonctionnalités

- Création d'événement (clic sur une date)
- Déplacement (drag & drop) et redimensionnement
- Suppression (règle métier : seul le créateur peut modifier/supprimer)
- Participants internes et invités externes avec envoi d'e-mails
- Gestion de plusieurs calendriers par utilisateur

## API

| Fichier | Méthode | Rôle |
|---------|---------|------|
| `backend/Event/load_event.php` | GET | Charge les événements d'un calendrier |
| `backend/Event/save_event.php` | POST | Crée, modifie ou supprime un événement |
| `backend/Event/users_list.php` | GET | Liste les utilisateurs pour le sélecteur |
| `backend/Calendars/calendars.php` | GET/POST | CRUD des calendriers |
| `Users/add_user.php` | POST | Ajoute un utilisateur |

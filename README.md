# BeemoConnect — Plugin GLPI

> Intégration automatique **BEEMO Suivi de Parc → Tickets GLPI**

[![GLPI](https://img.shields.io/badge/GLPI-10.x%20%7C%2011.x-blue)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://php.net)
[![Author](https://img.shields.io/badge/Author-DuoTech-orange)](https://charlytissot.me)

---

## Présentation

BEEMO ne propose pas d'API publique. Pourtant, chaque matin la plateforme envoie un **mail de suivi de parc** listant toutes les alertes du jour : RAID dégradé, Beemo déconnectée, jeux de sauvegarde en erreur, etc.

BeemoConnect exploite ce mail pour créer automatiquement les tickets correspondants dans GLPI, sans aucune intervention manuelle.

```
Mail BEEMO (IMAP ou Microsoft 365)
            │
            ▼
    Parse sections HTML
            │
            ▼
    Pour chaque alerte :
            ├── Entité GLPI identifiée via TAG (match exact)
            ├── Ticket existant ouvert ?
            │       ├── Oui → Tâche de relance (statut inchangé)
            │       └── Fermé/Résolu → Recréation du ticket
            └── Nouveau ticket :
                    ├── Demandeur : {TAG}_Portail
                    ├── Groupe : Équipe Infrastructure
                    ├── Statut : Nouveau
                    └── Sauvegarde en DB
```

---

## Fonctionnalités

- 📬 **Double source mail** : IMAP classique ou Microsoft 365 via Graph API (OAuth2)
- 🏷️ **Détection automatique du TAG client** : extraction depuis l'identifiant BEEMO, match exact sur le champ `tag` de l'entité GLPI
- 🔁 **Gestion des doublons** : tâche de relance sur ticket ouvert, recréation si résolu/fermé
- ⚡ **Urgence par section** : configurable individuellement (ex : RAID dégradé → Haute)
- 🔀 **Sections filtrables** : activer/désactiver chaque section du rapport indépendamment
- ⏰ **Cron autonome** : script `runcron.php` déclenché par le crontab système, heure + minutes configurables depuis l'interface
- 🖥️ **Interface d'administration** complète dans GLPI
- 💾 **Persistance** : état des tickets en base de données GLPI (table dédiée)
- 🔒 **Anti-doublon** : déduplification des messages et verrou d'exécution

---

## Compatibilité

| Composant | Version |
|---|---|
| GLPI | 11.x |
| PHP | 7.4+ |
| Base de données | MariaDB / MySQL |

---

## Installation

```sh
# 1. Déposer le plugin dans le dossier plugins GLPI
unzip beemoconnect.zip -d /var/www/glpi/plugins/

# 2. Dans GLPI : Configuration → Plugins → BeemoConnect → Installer → Activer
```

L'installation crée automatiquement deux tables :
- `glpi_plugin_beemoconnect_configs` — configuration clé/valeur
- `glpi_plugin_beemoconnect_states` — état des tickets (section|licence → ticket_id)

---

## Configuration

Accès : **Configuration → Plugins → BeemoConnect**

### 1. Connexion mail

**IMAP classique**

| Champ | Exemple |
|---|---|
| Serveur | `ssl0.ovh.net` |
| Port | `993` |
| Utilisateur | `beemo@exemple.fr` |
| Dossier source au meme niveau que la boite de reception pas un sous dossier | `Beemo` | 
| Dossier traité au meme niveau que la boite de reception pas un sous dossier | `Traite` |
| SSL/TLS | ✅ |

**Microsoft 365 (Graph API)**

| Champ | Description |
|---|---|
| Tenant ID | ID du tenant Azure |
| Client ID | ID de l'application Azure |
| Client Secret | Secret de l'application |
| Adresse mail | `mail@entreprise.fr` |
| Dossier source | Nom du dossier racine au meme niveau que la boite de reception pas un sous dossier (ex : `Beemo`) |
| Dossier traité | Nom du dossier destination au meme niveau que la boite de reception pas un sous dossier (ex : `Traite`) |

> **Permissions Azure requises** : `Mail.Read` et `Mail.ReadWrite` (Application, consentement administrateur)

---

### 2. Détection du TAG client

Le TAG GLPI est extrait automatiquement de l'identifiant BEEMO en ignorant les préfixes configurés.

```
Identifiant BEEMO : [B2B] CLIENT1 17879 > 17880
Préfixe configuré : [B2B]
TAG extrait       : CLIENT1  ← doit correspondre exactement au champ Tag de l'entité GLPI
```

Préfixes à ignorer (un par ligne) :
```
[B2B]
[EVAL]
[VM]
```

---

### 3. Paramètres GLPI

| Paramètre | Description | Défaut |
|---|---|---|
| Groupe assigné (ID) | ID du groupe Infrastructure | `9` |
| Source de la demande (ID) | ID de "Supervision" | `10` |
| Urgence par défaut | Très basse → Très haute | `Moyenne` |
| Heure d'exécution | Heure + minutes | `08h00` |
| Licences à ignorer | Une licence par ligne | vide |

---

### 4. Sections du rapport

Chaque section peut être activée/désactivée et avoir sa propre urgence.

| Section | Défaut | Urgence |
|---|---|---|
| Beemo avec un RAID dégradé | ✅ | **Haute** |
| Beemo déconnectées du centre de sauvegarde | ✅ | Moyenne |
| Beemo ne pouvant synchroniser leurs données | ✅ | Moyenne |
| Jeux de sauvegarde serveur en erreur | ✅ | Moyenne |
| Jeux de sauvegarde poste en erreur | ✅ | Moyenne |
| Jeux de sauvegarde non planifiés | ✅ | Moyenne |
| Beemo2Beemo dont la licence est expirée | ❌ | Moyenne |
| *(toutes les autres sections)* | ✅ | Moyenne |

---

## Cron

Lors de la sauvegarde, le plugin écrit automatiquement dans le crontab système :

```cron
50 7 * * * /usr/local/bin/php /var/www/glpi/plugins/beemoconnect/front/runcron.php >> /tmp/beemoconnect.log 2>&1
```

**Logs** :
```
2026-04-13 07:50:01 [BeemoConnect] Démarrage traitement...
2026-04-13 07:50:45 [BeemoConnect] OK — Créés: 89 | Relances: 37 | Ignorés: 5
```

```sh
# Consulter les logs
tail -f /tmp/beemoconnect.log

# Lancer manuellement
/usr/local/bin/php /var/www/glpi/plugins/beemoconnect/front/runcron.php
```

---

## Structure des fichiers

```
beemoconnect/
├── setup.php                     ← Déclaration plugin (init, hooks GLPI)
├── hook.php                      ← Installation / désinstallation (tables SQL)
├── inc/
│   ├── beemoconnect.class.php    ← Classe principale GLPI
│   ├── config.class.php          ← Lecture/écriture configuration (clé/valeur DB)
│   └── processor.class.php      ← Moteur de traitement BEEMO → GLPI
├── front/
│   ├── config.php                ← Page d'administration GLPI
│   ├── config.form.php           ← Handler POST
│   └── runcron.php               ← Script cron autonome (CLI)
└── ajax/
    ├── test_connection.php       ← Test connexion mail
    ├── run.php                   ← Lancement manuel
    └── fix_cron.php              ← Enregistrement tâche cron GLPI
```

---

## Notes techniques GLPI 10/11+

- `plugin_init_{nom}()` obligatoire (pas `plugin_{nom}_init()`)
- Pas de `Plugin::registerClass()` (déprécié en GLPI 10+)
- CSRF : lecture depuis `meta[property="glpi:csrf_token"]` + header `X-Glpi-Csrf-Token` pour XHR
- `$DB->doQuery()` pour les DDL, `$DB->insert()` / `$DB->request()` pour les données
- `glpi_entities` : pas de colonne `is_deleted`
- Bootstrap CLI : `vendor/autoload.php` + `new Kernel('production', false)` + `$kernel->boot()`
- L'assignation d'un groupe force le statut "En cours" → PUT forcé en "Nouveau" après

---

## Auteur

CharlyTissot — [charlytissot.me](https://charlytissot.me)
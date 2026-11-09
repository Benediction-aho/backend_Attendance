# Sécurité – Assistance Ghana Attendance

Document de référence pour la sécurité de la refonte (Laravel + PostgreSQL + React PWA).
Il complète `SPECS_REFONTE.md`.
Statut : **en cours de rédaction**.

---

## 1. Blocage après échecs de connexion

### Règle (employés et admins)

| Série | Échecs consécutifs | Conséquence |
|---|---|---|
| 1re série | 5 échecs | Connexion bloquée pendant **10 minutes** |
| 2e série | 5 nouveaux échecs | Connexion bloquée pendant **20 minutes** |
| 3e série | 5 nouveaux échecs | **Compte verrouillé** : plus aucune connexion possible |

- Le compteur est **remis à zéro** après une connexion réussie.
- Pendant un blocage, toute tentative est refusée **sans vérifier le code / mot de passe**, avec un message indiquant le temps restant.
- Nombre d'échecs, durées et nombre de séries sont **paramétrables** dans la configuration du super admin (valeurs ci-dessus par défaut).

### Déverrouillage

| Compte verrouillé | Qui peut le déverrouiller | Comment |
|---|---|---|
| Employé | Super admin, ou admin ayant l'autorisation | Réactiver l'accès, **ou** générer un **nouveau code d'identification unique** envoyé par email |
| Admin | **Super admin uniquement** | Réactiver l'accès, ou générer un nouveau code envoyé par email |

- Au verrouillage : notification (application + email) aux personnes qui peuvent déverrouiller, et email à l'utilisateur concerné.
- Chaque blocage, verrouillage et déverrouillage est inscrit dans le **journal d'audit** (section 6).

### Comptage par appareil (décision)
- Les échecs sont comptés **par appareil** : 5 / 5 / 5 échecs depuis un même appareil bloquent ou verrouillent le compte **sur cet appareil seulement**.
- Ainsi, une personne malveillante qui connaît l'email d'un employé ne peut pas le bloquer : l'employé continue à se connecter depuis son propre téléphone.
- Garde-fou : si des échecs viennent de **plusieurs appareils différents** sur le même compte (attaque), le compte est verrouillé entièrement et les admins sont alertés.

### Protection supplémentaire
- Limite de tentatives aussi **par adresse IP**, pour empêcher quelqu'un d'essayer plusieurs comptes à la suite.
- Message d'erreur **identique** que l'email existe ou non (« Invalid email or code »), pour ne pas révéler quels comptes existent.

## 2. Connexion du super admin

- Connexion **sans mot de passe** : le super admin saisit son email et reçoit **à chaque connexion un nouveau code à usage unique** par email.
- Ce code est **valable quelques minutes** (par défaut 10 min, paramétrable) et ne fonctionne **qu'une seule fois**.
- Aucun code n'est **jamais écrit dans le code source** (rien de codé en dur) : il est généré par le système au moment de la connexion, stocké **haché** en base, et envoyé uniquement à son adresse email.
- Le changement d'adresse email du super admin se fait **par email**, avec confirmation sur l'ancienne et la nouvelle adresse.
- La règle de blocage (5 / 5 / 5 échecs, section 1) s'applique aussi au super admin. Les demandes de code sont elles aussi limitées en nombre (pour éviter l'envoi massif d'emails).
- **Déverrouillage** : si son compte est verrouillé, le super admin reçoit un **lien de récupération** par email (valable une durée limitée, utilisable une seule fois) qui rétablit l'accès. Un autre super admin peut aussi le déverrouiller.
- Le premier super admin est créé **une seule fois** par une commande d'installation côté serveur (pas par une page publique). La page publique de « setup » de l'ancien système est supprimée.

## 3. Ne pas exposer la technologie utilisée

Objectif : une personne qui ouvre le lien ou les outils de développement du navigateur ne doit pas voir quelle technologie est utilisée.

Mesures :
- **Pas de « source maps »** en production : le code React n'est visible que sous forme compressée et illisible (minifié, noms raccourcis).
- Suppression des en-têtes qui révèlent la technologie : `X-Powered-By`, versions de PHP, de Laravel, du serveur web (nginx/Apache).
- Nom du cookie de session neutre (pas `laravel_session`), pas de jeton CSRF au nom de Laravel visible.
- **Mode debug désactivé** en production : aucune page d'erreur détaillée, aucune trace d'erreur. Les erreurs renvoient un message générique ; le détail reste dans les journaux du serveur.
- Pas de documentation d'API (Swagger) accessible publiquement en production.
- Pas de fichiers sensibles accessibles (`.env`, `.git`, `composer.json`, `package.json`, listes de répertoires).
- Pas de commentaires, de noms de bibliothèques ou de mentions de versions dans le HTML envoyé.

**Limite à connaître** : tout ce que le navigateur exécute est, par principe, téléchargé sur l'appareil. On peut le rendre illisible et retirer toutes les signatures, mais pas le rendre totalement invisible. Les **requêtes** vers le serveur restent visibles dans l'onglet « Réseau ». C'est pour cela que la règle de la section 4 est essentielle : le frontend ne reçoit jamais rien de sensible.

## 4. Tout ce qui est sensible se fait côté serveur

Le frontend ne sert qu'à **afficher** et à **envoyer des demandes**. Toutes les décisions sont prises par le backend.

| Sujet | Règle |
|---|---|
| Base de données | Le frontend **ne parle jamais directement** à la base (ni à Supabase). Aucune clé Supabase dans le frontend. |
| Clés secrètes | Clé Resend, clés Supabase, secrets de jetons : **uniquement côté serveur**, dans les variables d'environnement. |
| Heure | L'heure de check-in / check-out, de retard, des envois du vendredi est **toujours celle du serveur**, jamais celle du téléphone. |
| Géolocalisation | Le téléphone envoie sa position ; **le serveur** calcule la distance, vérifie la précision et décide si le check-in est valide. |
| Autorisations | Chaque demande est vérifiée **côté serveur** (rôle + autorisations de l'admin). Masquer un bouton dans l'interface ne suffit jamais. |
| Codes d'identification | Générés, hachés et vérifiés **côté serveur** ; jamais renvoyés au frontend. |
| Rapports | Journaliers, hebdomadaires et mensuels calculés et générés **côté serveur**. |
| Emails | Envoyés **uniquement par le serveur**. |
| Données renvoyées | Le serveur ne renvoie **que** les champs dont l'utilisateur a besoin et qu'il a le droit de voir (jamais de mot de passe, code, jeton, données d'autres employés sans autorisation). |

## 5. Autres mesures de base

### Comptes et sessions
- Codes et mots de passe **hachés** (bcrypt / Argon2), jamais stockés en clair.
- Code d'identification envoyé par email : **durée de validité limitée** avant la première connexion (par défaut 48 h, paramétrable), puis l'utilisateur est invité à le changer.
- Exigences minimales pour un nouveau mot de passe (longueur, refus des mots de passe trop courants).
- Sessions avec **expiration** ; déconnexion de toutes les sessions après un changement de code / mot de passe ou un blocage par un admin.
- Un compte **bloqué ou supprimé** perd l'accès **immédiatement**, y compris les sessions déjà ouvertes.

### Échanges
- **HTTPS obligatoire** partout (web, application, API).
- CORS limité aux seules adresses de l'application (pas de `*`).
- Protection contre les attaques classiques : injections SQL (requêtes préparées), XSS (échappement des contenus saisis), CSRF.
- Limitation du nombre de requêtes par utilisateur / IP sur toute l'API.

### Fichiers (photos, rapports PDF)
- Types acceptés vérifiés côté serveur : images pour la photo, **PDF uniquement** pour les rapports ; taille maximale.
- Stockage **privé** : les fichiers ne sont accessibles que par des liens temporaires générés par le serveur pour les personnes autorisées.
- Noms de fichiers générés par le serveur (pas le nom d'origine).

### Données personnelles
- Date de naissance, adresse, téléphone, WhatsApp : visibles uniquement par l'employé lui-même et les admins autorisés.

### Géolocalisation
- Une PWA ne peut pas détecter les applications de fausse position GPS. Garde-fous : contrôle de la précision et de la cohérence des positions côté serveur, méthode de check-in (GPS / Wi-Fi) enregistrée et visible par les admins.

### Wi-Fi du bureau (détection par adresse IP)
- Le serveur lit l'adresse réelle du client uniquement à travers des **intermédiaires de confiance** déclarés (répartiteur, proxy) ; l'en-tête `X-Forwarded-For` d'une source non déclarée est ignoré, pour qu'un employé ne puisse pas se faire passer pour le bureau.
- Le frontend appelle l'API **directement** (pas à travers un proxy qui masquerait l'adresse du téléphone).
- Liste des adresses du bureau modifiable uniquement par le super admin, chaque modification inscrite au journal d'audit.
- Recommandé : bloquer le **Relais privé iCloud** et les VPN sur le Wi-Fi du bureau (sinon l'adresse du téléphone est masquée).

## 6. Journal d'audit

Voir aussi `SPECS_REFONTE.md`, section 9 bis : le super admin consulte tous les journaux (actions, erreurs, sécurité) de chaque compte.

Enregistrement (qui, quoi, quand, depuis quelle IP / quel appareil) de :
- connexions réussies et échouées, blocages, verrouillages, déverrouillages ;
- création, blocage, suppression d'utilisateurs ; génération de codes ;
- changement d'autorisations, promotion en admin / super admin ;
- modification de la configuration ;
- check-in / check-out automatiques et anticipés.

Consultable par le super admin. Non modifiable.

## 7. Exploitation

- **Sauvegardes** automatiques de la base de données, avec test de restauration.
- Mises à jour de sécurité régulières des dépendances (Laravel, React).
- Variables d'environnement séparées pour test (Supabase) et production.

---

## Décisions prises

- Super admin : code à usage unique par email à chaque connexion (pas de code fixe).
- Super admin verrouillé : lien de récupération par email.
- Admins : connexion par email + mot de passe, **sans** double vérification par email.
- Accès web : **pas de restriction** (ni par réseau, ni par rôle).

## Questions ouvertes

Aucune pour l'instant.

# Refonte – Assistance Ghana Attendance

Document vivant : il sera complété au fur et à mesure des spécifications.
Statut : **en cours de rédaction**. Aucun code modifié pour l'instant.

La sécurité est décrite dans un document séparé : `security.md`.

---

## 0. Objectifs généraux

- Système **scalable**, conçu pour tenir **5 à 10 ans** sans maintenance majeure.
- Tout ce qui est « règle métier » (horaires, distances, délais, jours d'envoi…) est **paramétrable depuis l'interface du super admin**, jamais écrit en dur dans le code.
- Fuseau horaire de référence : **Africa/Accra** (paramétrable).

## 1. Choix techniques

| Élément | Décision | Statut |
|---|---|---|
| Backend | **PHP – Laravel** | ✅ validé |
| Base de données | **PostgreSQL** (Supabase pour commencer) | ✅ validé |
| Données existantes | **On repart de zéro** (pas de migration depuis MongoDB) | ✅ validé |
| Hébergement – phase de test | Base : Supabase. Frontend : Vercel. Backend Laravel : hébergeur PHP / Docker (voir `FIABILITE.md`) | ⏳ à confirmer |
| Hébergement – production | **Serveur de l'entreprise** (backend + frontend), après validation des tests | ✅ validé |
| Emails | **Resend** (compte à créer, domaine d'envoi à vérifier) | ✅ validé |
| Frontend | **React** (application monopage) + **Tailwind CSS** | ✅ validé |
| Animations | Bibliothèque d'animation pour React (Motion / Framer Motion) | ⏳ proposé |
| Icônes | Jeu d'icônes fines et sobres (Lucide, par exemple), utilisé **uniquement dans le menu** | ⏳ proposé |
| Langue | **Anglais uniquement** (le français est retiré) | ✅ validé |
| Thème | **Un seul thème clair** (le thème sombre est retiré) | ✅ validé |
| Logo | **Logo Assistance Ghana conservé** | ✅ validé |
| Application mobile | **PWA (Progressive Web App)** : l'application web s'installe sur l'écran d'accueil (iPhone via Safari « Ajouter à l'écran d'accueil », Android via Chrome). Pas d'application native, pas d'App Store. La plupart des employés ont un iPhone. | ✅ validé |
| Accès web | Interface web disponible pour tous (employés, admins, super admins), dans le navigateur ou installée en PWA | ✅ validé |
| Stockage des fichiers (photos de profil, rapports PDF) | Supabase Storage (ou équivalent compatible S3) | ⏳ proposé |
| Tâches planifiées (17h, 18h, vendredi) | Planificateur et files d'attente Laravel (scheduler + queues) | ⏳ proposé |
| Notifications push sur téléphone | **Web Push** (standard du web) : fonctionne sur Android et sur iPhone (iOS 16.4+) quand la PWA est installée sur l'écran d'accueil | ✅ validé |
| Schéma de données | Reprendre le schéma actuel (User, Attendance, CheckInAttempt, Task), converti en tables SQL, puis étendu | ✅ validé |

## 2. Rôles et autorisations

### Rôles

| Rôle | Description |
|---|---|
| **Super admin** | A la main sur **tout** le système : pages de configuration, création / suppression / blocage des utilisateurs, attribution des accès, confirmation des comptes, envoi des mots de passe uniques, réception des notifications d'inscription. Peut créer des **admins** et d'autres **super admins**. |
| **Admin** | N'a que les autorisations que le super admin lui donne. |
| **Employé** | Employé ou stagiaire. Pointage, tâches, clients, rapports, profil. |

### Autorisations attribuables par le super admin (cases à cocher par admin)

Chaque autorisation est un **palier** activable séparément dans la configuration :

- Voir les performances
- Voir les retards (qui est venu en retard)
- Voir les clients apportés par chaque employé
- Voir les tâches effectuées
- Valider les check-in mission
- Valider les nouveaux appareils Face ID
- Faire un check-in à la place d'un employé (secours)
- Gérer les congés des employés
- Modifier / retirer les jours off et de télétravail des employés
- Ajouter un utilisateur directement
- Confirmer les comptes / envoyer le mot de passe unique
- Bloquer / débloquer un utilisateur
- Supprimer un utilisateur
- Voir les rapports (journaliers, hebdomadaires, PDF)
- Voir les activités / le travail fait par jour
- Voir les statistiques et analyses
- Recevoir les notifications et emails de rapports hebdomadaires
- Devenir super admin (promotion)

Règle de sécurité : il doit toujours rester **au moins un super admin actif** (impossible de supprimer, bloquer ou rétrograder le dernier).

## 3. Page(s) de configuration (super admin)

Une ou deux pages regroupant **tous les paramètres du système**, modifiables avec des boutons, interrupteurs et champs.

| Paramètre | Valeur par défaut |
|---|---|
| Coordonnées GPS du bureau | 5.595223, -0.216703 |
| Rayon du périmètre de validation du check-in | **50 m** |
| Nombre maximum de jours off par semaine | **2** |
| Taille maximale d'un rapport PDF | **10 Mo** |
| Taille maximale des pièces jointes d'un email | **30 Mo** (au-delà : liens sécurisés) |
| Durée de conservation des journaux | **12 mois** |
| Jours fériés | liste à remplir |
| Heure de début du retard | **08:10** |
| Heure du rappel de check-in | **08:05** |
| Vérification Face ID au check-in | **activée** |
| Heure de fin de journée (départ anticipé avant) | **17:00** |
| Heure de check-out automatique (réinitialisation de la journée) | **00:00** |
| Comptabiliser les heures de travail | **désactivé** |
| Heure d'envoi du rapport mensuel (le 26) | soir (heure à définir) |
| Heure du rappel aux employés n'ayant pas envoyé leur rapport hebdomadaire | **vendredi 16:30** |
| Motif obligatoire pour un check-out avant l'heure de fin de journée | activé |
| Heure de génération du rapport journalier | **17:00** |
| Fenêtre d'envoi du rapport hebdomadaire | **vendredi, 16:00 → 17:00** |
| Jour de génération du rapport mensuel | **le 26 de chaque mois** |
| Destinataires du rapport mensuel (en plus du super admin) | admins sélectionnés par le super admin |
| Fuseau horaire | Africa/Accra |

Paramètres possibles à valider plus tard : jours ouvrés, jours fériés, plusieurs sites/bureaux, horaires différents par type (stagiaire / employé), langue par défaut, durée de session.

## 4. Création de compte et connexion

### Employé : parcours complet
1. L'employé arrive sur la **page de connexion**. S'il n'a pas de compte, il clique sur **créer un compte**.
2. Formulaire d'inscription (**sans mot de passe**) :
   - email ;
   - prénom et nom ;
   - type : **Intern** (stagiaire) ou **Employee** (employé) ;
   - **intitulé du poste**, tel qu'écrit sur sa lettre de nomination (appointment letter).
3. Il envoie le formulaire. Le compte est **en attente de validation** : impossible de se connecter.
4. Le **super admin** et les admins autorisés reçoivent une **notification** (application + email) : « Telle personne veut créer un compte ».
5. L'admin, depuis sa page, **valide la demande** : le système génère un **code d'identification unique** et l'envoie **automatiquement par email** (Resend) à l'employé. Tout se passe dans le système, l'admin n'a rien à copier.
6. L'employé va sur la page de connexion et se connecte avec **son email + le code d'identification**.
7. Une fois connecté, il peut **changer ce code** (le remplacer par son propre mot de passe) et **compléter son profil**.

Variante : un super admin / admin autorisé peut aussi créer un compte directement ; le code d'identification part alors par email de la même manière.

### Admins
- Un admin (ou super admin) créé par un super admin reçoit lui aussi un **code d'identification par email**, qu'il peut changer après sa première connexion.

Également prévu : **code / mot de passe oublié** par email.

### Super admin
- Se connecte **sans mot de passe** : il saisit son email et reçoit **à chaque connexion un nouveau code à usage unique** par email (valable quelques minutes, par défaut 10 min, paramétrable ; utilisable une seule fois ; stocké haché). Voir `security.md` section 2.
- Le blocage 5 / 5 / 5 s'applique aussi à lui ; s'il est verrouillé, il reçoit un **lien de récupération** par email (ou un autre super admin le déverrouille).
- Le changement de son adresse email se confirme **sur l'ancienne et la nouvelle adresse**.
- Le premier super admin est créé une seule fois par une commande côté serveur (pas de page publique de « setup »).
- Gère tout depuis son tableau de bord, y compris l'ajout d'admins et de super admins.
- Un **nouveau super admin** a **toutes** les fonctionnalités du super admin.
- Un **nouvel admin** n'a que les autorisations cochées pour lui (voir section 2).

## 5. Profil utilisateur

L'utilisateur peut modifier son compte :
- nom et prénom ;
- date de naissance ;
- lieu d'habitation (adresse) ;
- numéro de téléphone ;
- numéro WhatsApp ;
- email ;
- photo de profil (upload) ;
- code / mot de passe ;
- autres informations utiles pour la sécurité (contact d'urgence, par exemple — à confirmer).

Les changements sensibles passent **par email** (Resend) :
- changement d'email : lien de confirmation envoyé à la nouvelle adresse (et alerte à l'ancienne) ;
- changement de mot de passe : email de confirmation / d'alerte.

## 6. Pointage

### Check-in manuel
1. **Périmètre GPS** : l'employé doit être dans le périmètre de **50 m** autour du bureau (précision prise en compte). Le serveur décide.
2. **Vérification Face ID** : une fois le périmètre validé, l'application demande **Face ID** (ou l'empreinte / la reconnaissance faciale sur Android). Le check-in n'est enregistré qu'après cette vérification. Objectif : empêcher qu'une autre personne pointe à la place de l'employé.
- Retard à partir de **08:10**.
- Sans connexion internet, le check-in est impossible (l'heure vient du serveur) : message demandant de se connecter.
- Le check-in **mission** et le check-in **télétravail** demandent aussi Face ID (sans contrôle de périmètre).

### Face ID : fonctionnement
- Technique : **passkey (WebAuthn)**, standard du web, fonctionne dans la PWA sur iPhone (Safari) et Android (Chrome), sans application native.
- **Enregistrement** : à la **première connexion** (après réception du code par email), l'employé enregistre Face ID sur son téléphone. Sans cet enregistrement, il ne peut pas pointer. (L'enregistrement ne peut pas se faire au moment de l'inscription : le compte n'est pas encore validé.)
- **Ce que le serveur reçoit** : seulement une preuve cryptographique que le propriétaire du téléphone a été reconnu. **Aucune photo ni donnée du visage** ne quitte le téléphone ni n'est stockée.
- **Nouveau téléphone / Face ID réinitialisé** : l'employé demande un nouvel enregistrement, qui doit être **validé par un admin autorisé** (nouvelle autorisation « Valider les nouveaux appareils Face ID »). L'ancien enregistrement est révoqué.
- **Limites à connaître** :
  - Face ID reconnaît **le visage enregistré dans le téléphone**, pas l'identité de l'employé : si l'employé donne son téléphone et son code à quelqu'un, le système ne peut pas le savoir.
  - Si Face ID échoue plusieurs fois, iPhone propose le **code du téléphone** à la place ; le web ne peut pas l'interdire.
  - Garde-fou : un enregistrement Face ID est lié à **un seul appareil validé** ; une passkey synchronisée sur un autre appareil n'est acceptée qu'après validation admin.
- **Secours** : si Face ID est impossible (téléphone cassé, oublié), un admin autorisé peut faire le check-in à la place de l'employé, avec motif, inscrit au journal.

### Pas de check-in automatique
- Le check-in automatique et la détection du Wi-Fi du bureau sont **retirés**. Le check-in est toujours **manuel** (GPS + Face ID).
- Contre les oublis : **rappel par notification à heure fixe** (par défaut **08:05**, configurable) aux employés attendus qui n'ont pas encore pointé.

### Check-out
- Check-out manuel, comme aujourd'hui, **sans obligation d'être au bureau** : l'employé peut oublier et faire son check-out de chez lui.
- La position est quand même relevée au moment du check-out : l'historique affiche **la distance par rapport au bureau** (« au bureau » / « à 3,2 km du bureau »), ou « position non disponible » si l'employé a refusé la localisation.
- **Check-out avant 17:00** (départ anticipé) : l'application **demande un motif** à l'employé. Le check-out n'est validé qu'une fois le motif saisi. Le motif est visible par les admins autorisés.
- Si l'employé n'a pas fait son check-out, le système le fait **automatiquement à 00:00** pour **réinitialiser le compte** pour le jour suivant. La journée est marquée **« check-out oublié »** dans l'historique.

### Heures de travail
- **Les heures de travail ne sont plus comptées** (les employés ne sont pas payés à l'heure) : elles disparaissent des statistiques, tableaux de bord et rapports.
- Les heures de check-in et de check-out restent enregistrées.
- Un interrupteur dans la configuration du super admin permettra de **réactiver** le calcul des heures plus tard.
- **On n'utilise pas** l'heure de sortie du périmètre pour le check-out : un employé peut quitter le périmètre en cours de journée (course, rendez-vous…) sans avoir fini sa journée.

### Accès conditionné au check-in
- **Inchangé** : l'employé ne peut faire aucune action dans son compte (tâches, clients, rapports…) tant qu'il n'a pas fait son check-in du jour.

### Check-in mission (travail à l'extérieur)
- Un employé qui part directement en mission (chez un client…) peut faire un **check-in mission** depuis n'importe où, avec un motif.
- Le check-in mission doit être **validé par un admin autorisé** (nouvelle autorisation : « Valider les check-in mission »). En attendant, l'employé peut déjà utiliser l'application.
- L'heure enregistrée est celle de la demande. Retard calculé comme pour un check-in normal.

## 6 bis. Jours off, télétravail, congés et jours fériés

### Jours off et jours de télétravail (selon le contrat)
- Les jours off et de télétravail sont **fixés par le contrat** de l'employé (ex. tous les mercredis).
- L'employé les **sélectionne une seule fois** sur son tableau de bord, conformément à son contrat (au maximum **2 jours off** par semaine, configurable).
- Une fois enregistrés, **seul un admin autorisé** peut les modifier ou les retirer (par exemple si le contrat change). L'employé ne peut plus les changer lui-même.
- Ces jours **ne sont pas comptés comme absences** dans les rapports et statistiques.
- Pas de check-in automatique ni de rappel ces jours-là.
- **Jour de télétravail** : l'employé fait un **check-in télétravail en un clic**, sans contrôle de position, pour pouvoir utiliser l'application. Le retard est calculé comme pour un check-in normal.

### Congés et jours fériés (gérés par l'admin)
- **Jours fériés** : liste gérée dans la configuration (super admin / admin autorisé).
- **Congés** : déclarés par un admin autorisé pour un employé (dates de début et de fin).
- Ces jours ne comptent pas comme absences, pas de check-in automatique, pas de rappel, et l'employé n'est pas attendu pour le rapport du vendredi si le vendredi en fait partie.

## 7. Tâches, clients et rapports

### Tâches de la journée
- **Le matin (facultatif)** : l'employé peut ajouter à l'avance les tâches qu'il prévoit de faire.
- **Quand il finit une tâche** : il la coche (si elle était déjà prévue) ou l'écrit, puis la marque comme **effectuée**.

### Clients gagnés
- L'employé ajoute chaque client gagné : **nom du client**, **service** pris (**saisi librement**), **nombre de fois** (quantité).
- Pour que les regroupements des rapports restent justes (« Residence Permit » = « residence permit »), la saisie propose en **suggestion** les services déjà saisis, et le regroupement ignore majuscules et espaces en trop.
- En fin de journée, il voit la **liste des clients qu'il a générés** dans la journée.

### Modifications
- L'employé peut **toujours modifier** ses tâches et ses clients, même après 17:00. Les rapports journaliers, hebdomadaires et mensuels sont recalculés à partir des données à jour.

### Rapport journalier (automatique, 17:00)
- Généré automatiquement à 17:00 **pour chaque utilisateur**, à partir des tâches et des clients de la journée.
- Contient un tableau : nombre de clients générés dans la journée et services réalisés.

### Rapport hebdomadaire (automatique)
- Couvre **du lundi au vendredi**.
- Généré automatiquement à partir des rapports journaliers de la semaine ; renouvelé chaque semaine.
- Comprend aussi un **récapitulatif des clients de la semaine** (nom, service, quantité).
- L'employé s'en sert pour **rédiger son rapport en PDF**, qu'il **upload** dans l'application.
- **Qui doit l'envoyer** : chaque employé et stagiaire **actif** qui était attendu ce vendredi (pas les admins, ni les comptes bloqués, en attente, en congé, en jour off ou les jours fériés). C'est sur cette liste que le système compte « qui a envoyé ».
- Taille maximale d'un PDF : **10 Mo** (configurable).
- **Fenêtre d'envoi : le vendredi, de 16:00 à 17:00.**
- **Rappel à 16:30** aux employés qui n'ont pas encore envoyé leur rapport (notification push + application).
- Le rapport envoyé est **automatiquement accompagné de la liste des clients générés** par l'employé dans la semaine (avec les services et quantités).

### Suivi des envois (vendredi 17:00)
- À 17:00, le système compte le nombre d'utilisateurs ayant envoyé leur rapport.
- Notification dans l'application + email au **super admin** et aux **admins autorisés à voir les rapports**, **même si tout le monde n'a pas envoyé** :
  - ils reçoivent les rapports de ceux qui les ont envoyés (consultables dans le tableau de bord, et envoyés par email) ;
  - les PDF sont joints à l'email tant que le total reste raisonnable (environ **30 Mo**) ; au-delà, l'email contient des **liens sécurisés** vers chaque PDF ;
  - la notification indique combien d'employés ont envoyé leur rapport.
- **Retardataires** : quand un employé envoie son rapport après 17:00, il est transmis **directement** aux personnes autorisées à le voir (application + email).

### Rapport mensuel automatique (le 26 de chaque mois)
- Généré automatiquement **le 26 de chaque mois**, à partir des check-in / check-out et des clients enregistrés.
- **Période couverte : du 26 du mois précédent au 26 du mois en cours, inclus** (choix validé : le 26 figure dans les deux rapports).
- Pour que la journée du 26 soit complète, le rapport est généré et envoyé **le 26 au soir** (heure paramétrable dans la configuration).
- Envoyé sous forme de **fichier PDF** joint à l'email.
- **Modèle de référence** : `modeles/Rapport_de_performance_Octobre_2026.pdf`. Le PDF généré doit le reproduire :
  - en-tête : « ASSISTANCE GHANA » (bleu) / « by GIMA Services » (orange) ;
  - **contenu en anglais** ;
  - titre : « Lateness and Client Performance Report by Employee » ;
  - tableau à 4 colonnes, une ligne par employé :

    | Employee | Late arrivals | Clients | Client services |
    |---|---|---|---|
    | LAST NAME First name | nombre (en **rouge**) | nombre sur 2 chiffres (« 02 »), ou « - » si aucun | quantité + service (« 02 Residence Permit »), un service par ligne, ou « - » si aucun |

  - pied de page : « Monthly Performance Report by Employee » ;
  - nom du fichier : `Rapport_de_performance_<Mois>_<Année>.pdf` (ex. `Rapport_de_performance_Octobre_2026.pdf`), le mois étant celui de l'envoi ;
  - pas de ligne de période ajoutée sous le titre : le PDF reste identique au modèle.
- Pas de détail des dates de retard : seulement le nombre.
- Destinataires :
  - le **super admin**, toujours ;
  - le ou les **admins sélectionnés** par le super admin pour ce rapport (paramètre de la configuration).
- Envoi **par email uniquement** (le rapport n'est pas affiché dans l'application).
- En plus, une **notification dans l'application** prévient chaque destinataire qu'il a reçu le rapport mensuel par email.

### Fin de mois (autres éléments)
- Mis de côté pour l'instant, à reprendre plus tard.

## 8. Notifications

| Événement | Destinataire | Canal |
|---|---|---|
| Nouvelle inscription | Super admin + admins autorisés | Application + email |
| Compte confirmé + mot de passe unique | Employé | Email |
| Changement d'email / de mot de passe | Employé | Email |
| Rappel de check-in (heure fixe, 08:05 par défaut) | Employé attendu sans check-in | Notification push + application |
| Demande de nouvel appareil Face ID | Admins autorisés | Application |
| Check-out anticipé avec motif | Admins autorisés | Application |
| Rappel : rapport hebdomadaire pas encore envoyé (vendredi 16:30) | Employés concernés | Notification push + application |
| Fin de la fenêtre d'envoi du vendredi (17:00) | Super admin + admins autorisés à voir les rapports | Application + email (avec les rapports reçus) |
| Rapport hebdomadaire envoyé en retard | Super admin + admins autorisés à voir les rapports | Application + email |
| Rapport mensuel du 26 | Super admin + admins sélectionnés | Email (rapport) + notification dans l'application |

## 9. Accès

- Les employés ont accès **par le navigateur** et par **la PWA installée** sur l'écran d'accueil.
- Les **notifications push sur iPhone** exigent que la PWA soit **installée sur l'écran d'accueil** (iOS 16.4 ou plus récent). L'application guide l'employé pour l'installer et autoriser les notifications au premier lancement.
- Sans installation ou sans autorisation, les notifications restent visibles dans le centre de notifications de l'application.

## 9 bis. Journaux d'activité (logs) – super admin

- Le super admin a accès à **tous les journaux** de l'application, pour savoir ce qui se passe sur chaque compte **sans se connecter au compte** de la personne.
- Contenu :
  - **actions** de chaque utilisateur : connexions, check-in / check-out (GPS + Face ID, mission, télétravail, par un admin), enregistrements et révocations Face ID, tâches, clients, envois de rapports, modifications de profil ;
  - **événements automatiques** : rappels, check-in automatiques, check-out de 00:00, rapports de 17:00 / vendredi / le 26, emails envoyés ou en échec ;
  - **erreurs** : erreurs du serveur **et** erreurs survenues dans l'application de l'employé (téléphone, navigateur, version de l'application, message d'erreur), rattachées à son compte ;
  - **sécurité** : échecs de connexion, blocages, verrouillages, changements d'autorisations (voir `security.md`).
- Recherche et filtres : par **utilisateur**, par **date**, par **type** (action / erreur / sécurité / automatique), par niveau de gravité.
- Les journaux ne peuvent être **ni modifiés ni supprimés** depuis l'application.
- Ne contiennent jamais de mot de passe, code ou jeton.
- Durée de conservation : **12 mois** par défaut (configurable).
- Nouvelle autorisation possible pour un admin : « Voir les journaux » (désactivée par défaut).

## 10. Expérience utilisateur et design

### Principes
- Refonte complète : **moderne, professionnelle, dynamique**, niveau « designer pro ». Rien de basique.
- **Animations sur toutes les pages**, utiles et guidantes : elles montrent à l'utilisateur quoi faire ensuite (apparition progressive des éléments, transitions entre étapes, retour visuel après chaque action, mise en avant de la prochaine action attendue).
- **Page de connexion et page d'inscription** entièrement repensées, très soignées et animées.
- Tableaux de bord professionnels pour chaque rôle (employé, admin, super admin).
- **Application entièrement en anglais**, y compris **les emails et les rapports** (journaliers, hebdomadaires, mensuel PDF).
- **Un seul thème, clair et simple.**
- **Le logo Assistance Ghana est conservé.**

### Icônes
- **Plus d'émojis ni d'icônes partout.**
- Icônes fines, sobres et bien choisies, **uniquement dans le menu**. Aucune icône ailleurs (ni sur la connexion, ni dans les pages).

### Couleurs

| Rôle | Couleur | Part dans le design (couleurs d'accent) |
|---|---|---|
| Couleur principale (bleu) | **#256EAD** | **80 %** : navigation, boutons principaux, titres, éléments actifs, graphiques |
| Couleur secondaire (orange) | **#EFA12F** | **20 %** : mises en avant ponctuelles, indicateurs, badges, appels à l'action secondaires |
| Fond | blanc cassé doux (pas un blanc pur), légèrement teinté | reste du design |
| Surfaces (cartes, panneaux) | blanc / gris très clair | — |

### Stack frontend
- React + **Tailwind CSS** (HTML, CSS, JavaScript/TypeScript).

---

## Questions ouvertes

Aucune pour l'instant.

## Reste à spécifier

- Suite des spécifications à venir (expérience utilisateur, autres pages…).

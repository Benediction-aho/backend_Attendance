# Plan de développement – Assistance Ghana Attendance (v2)

S'appuie sur : `SPECS_REFONTE.md` (fonctionnel), `security.md` (sécurité), `FIABILITE.md` (bugs et risques).
Statut : **proposition à valider** avant le début du développement.

---

## 1. Architecture générale

```
 Téléphone (PWA installée)             Navigateur (web)
            \                               /
             \                             /
              React + Tailwind (même code, un seul dépôt)
                            |
                     HTTPS, API JSON
                            |
                 Backend Laravel (API seulement)
          ┌─────────────┬──────────────┬─────────────────┐
          │ API         │ File d'attente│ Planificateur   │
          │ (requêtes)  │ (emails, push)│ (17h, 00h, le 26…)│
          └─────────────┴──────────────┴─────────────────┘
                 |                |               |
          PostgreSQL        Stockage fichiers    Resend (emails)
          (Supabase)        (Supabase Storage)   Web Push
```

Principes :
- Le **frontend n'a aucune logique sensible** : il affiche et envoie des demandes. Toutes les règles (heure, distance, retard, autorisations, rapports) sont dans le backend.
- Le backend est **sans état** : on peut lancer plusieurs instances derrière un répartiteur si le nombre d'employés augmente.
- Les tâches lentes (emails, push, PDF) passent par une **file d'attente** : elles ne ralentissent jamais un check-in.
- Chaque règle métier lit ses valeurs dans la **configuration** (base de données), jamais dans le code.

## 2. Technologies retenues

### Backend (`backend_Attendance`)

| Besoin | Outil |
|---|---|
| Framework | Laravel (version stable actuelle), PHP 8.3+ |
| Base de données | PostgreSQL (Supabase en test) |
| Authentification API | Laravel Sanctum (jetons par appareil) |
| File d'attente | Queue Laravel, pilote base de données (pas de Redis nécessaire au début) |
| Tâches planifiées | Scheduler Laravel (une seule tâche cron système par minute) |
| Emails | Resend (pilote natif Laravel) |
| Génération des PDF | DomPDF (rapport mensuel selon le modèle) |
| Stockage des fichiers | Supabase Storage via le protocole S3 (photos, PDF), accès par liens temporaires |
| Notifications push | Web Push (clés VAPID), Android et iPhone (PWA installée, iOS 16.4+) |
| Face ID | Passkeys WebAuthn (bibliothèque WebAuthn pour Laravel côté serveur, API WebAuthn du navigateur côté client) |
| Tests | Pest (tests unitaires et tests d'API) |
| Test de charge | k6 |
| Suivi des erreurs | Sentry (offre gratuite au début) |
| Livraison | Docker (image identique en test et en production) |

### Frontend (`frontend_Attendance`)

| Besoin | Outil |
|---|---|
| Base | React + TypeScript + Vite |
| Styles | Tailwind CSS (couleurs #256EAD 80 % / #EFA12F 20 %, fond blanc cassé) |
| Animations | Motion (Framer Motion) |
| Icônes | Lucide (menu uniquement) |
| Données serveur | TanStack Query (cache, nouvelles tentatives automatiques, rafraîchissement) |
| Formulaires | React Hook Form + Zod (validation) |
| Graphiques | Recharts |
| Application mobile | **PWA** : manifeste, service worker (mise à jour contrôlée, pas de cache des réponses de l'API), installation guidée sur iPhone et Android, Web Push |
| Tests | Vitest (composants), Playwright (parcours complets) |
| Hébergement test | Vercel |

## 3. Organisation des dépôts

- **Avant de remplacer l'ancien code** : poser une étiquette `legacy-v1` sur l'état actuel des deux dépôts (pour pouvoir y revenir).
- Le nouveau code **remplace** l'ancien dans les mêmes dépôts.
- Travail sur la branche `claude/upbeat-noether-f7defe` ; une **pull request par phase**, que vous relisez et fusionnez.
- **Intégration continue (GitHub Actions)** : à chaque envoi de code, les tests tournent automatiquement ; une phase n'est livrée que si tout est vert.

```
backend_Attendance/
  app/
    Domain/            ← règles métier par module (Attendance, Reports, Auth…)
    Http/              ← contrôleurs API, validations, réponses
    Jobs/              ← tâches de la file d'attente
    Console/           ← tâches planifiées
  database/migrations/ ← schéma PostgreSQL
  tests/               ← tests unitaires, d'API, de concurrence
  docker/              ← Dockerfile, configuration serveur

frontend_Attendance/
  src/
    app/               ← routes, mise en page, menu
    features/          ← un dossier par module (auth, attendance, tasks…)
    ui/                ← composants du design (boutons, cartes, tableaux, animations)
    lib/               ← client API, géolocalisation, appareils
  e2e/                 ← tests Playwright
```

## 4. Modèle de données (PostgreSQL)

Toutes les dates et heures sont stockées **avec fuseau horaire** ; le « jour de travail » est calculé par le serveur en heure d'Accra.

| Table | Contenu principal |
|---|---|
| `users` | prénom, nom, email (unique), téléphone, WhatsApp, date de naissance, adresse, photo, rôle (`super_admin` / `admin` / `employee`), type (`intern` / `employee`), intitulé du poste, statut (`pending` / `active` / `blocked` / `locked` / `deleted`), mot de passe haché, date d'expiration du code initial |
| `permissions`, `user_permissions` | paliers d'autorisation cochés pour chaque admin |
| `settings`, `setting_history` | tous les paramètres de la configuration, avec **date d'effet** et historique (qui a changé quoi, quand) |
| `work_schedules` | jours off et de télétravail par employé (selon contrat), verrouillés après sélection |
| `holidays`, `leaves` | jours fériés ; congés par employé |
| `attendances` | un pointage par employé et par jour (**contrainte unique**) : type de check-in (manuel / automatique / mission / télétravail), heure d'arrivée, position et précision, retard, **règle de retard utilisée**, validation de mission, check-out (manuel / automatique 00:00), distance au bureau, départ anticipé et motif |
| `webauthn_credentials` | passkeys Face ID par employé et par appareil (clé publique uniquement, statut validé / révoqué, validé par) |
| `checkin_attempts` | tentatives refusées (hors périmètre, position imprécise, fausse position) |
| `tasks` | tâches (prévue / effectuée, heure de réalisation) |
| `clients` | client, service saisi + version normalisée pour les regroupements, quantité |
| `daily_reports` | rapport journalier figé à 17:00 (recalculé si l'employé modifie ensuite) |
| `weekly_reports`, `weekly_report_files` | synthèse de la semaine, PDF envoyés (avec versions), heure d'envoi, en retard ou non |
| `monthly_reports` | période, fichier PDF, destinataires, date d'envoi |
| `notifications` | centre de notifications dans l'application |
| `devices`, `push_subscriptions` | appareils de chaque utilisateur (navigateur, PWA installée ou non, dernière activité) et abonnements Web Push |
| `login_attempts`, `login_codes` | échecs par appareil et par compte, blocages ; codes à usage unique et liens de récupération (hachés) |
| `activity_logs` | journaux d'activité, d'erreurs et de sécurité (ajout seulement, jamais modifiés) |
| `scheduled_runs` | trace de chaque tâche planifiée exécutée, pour **ne jamais l'exécuter deux fois** et rattraper celles manquées |

## 5. Phases de développement

Chaque phase se termine par une démonstration et des critères de validation. On ne passe à la suivante qu'une fois la précédente validée.

### Phase 0 – Préparation
- Étiquette `legacy-v1`, nouveaux squelettes Laravel et React, Docker, intégration continue.
- Création des comptes et services (voir section 8).
- Environnement de test en ligne : base Supabase, backend sur l'hébergeur de test, frontend sur Vercel.
- **Validation** : les deux applications « vides » sont en ligne, la connexion backend ↔ base fonctionne, les tests automatiques tournent à chaque envoi.

### Phase 1 – Socle backend : comptes, sécurité, configuration
- Schéma de base complet (migrations).
- Moteur de **configuration** avec dates d'effet et historique.
- Rôles et **paliers d'autorisation**.
- Parcours d'inscription : demande → notification (appli + email) → validation par l'admin → **code d'identification par email** → connexion → changement du code.
- Connexion du super admin par **code à usage unique**, lien de récupération.
- **Blocages par appareil** (5 / 5 / 5), verrouillage, déverrouillage.
- Profil (photo, date de naissance, adresse, téléphone, WhatsApp), changement d'email par email.
- **Journaux d'activité**.
- En-têtes de sécurité, aucune trace de la technologie, mode debug coupé.
- Commande d'installation du premier super admin.
- **Validation** : tests automatiques de tous les cas de connexion et de blocage ; aucun en-tête ne révèle PHP / Laravel.

### Phase 2 – Socle frontend : design, connexion, administration des comptes
- Système de design : couleurs, typographie, composants, animations de transition et de guidage.
- Pages de **connexion** et d'**inscription** entièrement repensées et animées.
- Menu avec icônes Lucide, mise en page responsive (téléphone, tablette, ordinateur).
- Profil employé.
- Super admin / admin : demandes d'inscription, liste des utilisateurs, création d'admins et de super admins, autorisations, blocage, suppression, déverrouillage.
- **Pages de configuration** (tous les paramètres avec boutons et interrupteurs).
- **Validation** : parcours complet inscription → validation → email → première connexion → changement de code, testé automatiquement de bout en bout.

### Phase 3 – Pointage (sans l'automatique)
- Check-in manuel avec **précision GPS**, nouveaux essais automatiques et messages clairs pour chaque cas ; puis **vérification Face ID (passkey WebAuthn)** ; check-in de secours par un admin.
- Check-out avec **distance au bureau** ; **motif obligatoire** avant 17:00.
- Check-in **mission** (validation admin) et **télétravail** (un clic).
- Jours off / télétravail selon contrat, congés, jours fériés.
- **Check-out automatique à 00:00** (« check-out oublié »).
- Blocage de l'application tant que le check-in n'est pas fait.
- Horloge affichée synchronisée sur le serveur.
- **Validation** :
  - **test de charge : 200 check-ins simultanés, zéro erreur, zéro doublon** ;
  - tests du calcul de retard, de la frontière de minuit, des jours off / congés / fériés.

### Phase 4 – PWA, Face ID et notifications
- PWA : installation guidée sur iPhone (Safari) et Android (Chrome), service worker avec mise à jour contrôlée, aucune réponse de l'API mise en cache.
- **Face ID (passkey WebAuthn)** : enregistrement à la première connexion, vérification à chaque check-in, validation admin d'un nouvel appareil, révocation.
- **Web Push** + centre de notifications ; **rappel de check-in à heure fixe** (08:05).
- Détection des navigateurs intégrés (WhatsApp, Facebook) avec message pour ouvrir Safari / Chrome.
- **Validation** : essais réels au bureau avec plusieurs iPhone et Android (Face ID, empreinte, échec, nouvel appareil).

### Phase 5 – Tâches, clients et rapports
- Tâches (prévues le matin / effectuées), clients (service libre avec suggestions), modification à tout moment.
- **Rapport journalier à 17:00**.
- **Rapport hebdomadaire** (lundi → vendredi) avec récapitulatif des clients.
- Envoi du PDF le vendredi **16:00 → 17:00**, **rappel à 16:30**, envoi aux admins à **17:00**, transmission directe des envois en retard, remplacement du PDF.
- **Rapport mensuel du 26** en PDF, identique au modèle (en anglais), envoyé par email au super admin et aux admins choisis, avec notification dans l'application.
- Tâches planifiées **rattrapées** si manquées et **jamais doublées**.
- **Validation** : rapports générés sur des données de test connues et comparés aux résultats attendus ; PDF mensuel comparé au modèle.

### Phase 6 – Tableaux de bord et journaux
- Tableau de bord employé (statut du jour, tâches, clients, statistiques, rapports).
- Tableaux de bord admin / super admin (présences, retards, clients, services, rapports reçus, missions à valider), selon les autorisations.
- Visionneuse de **journaux** pour le super admin (filtres par utilisateur, date, type, gravité).
- **Validation** : chaque écran testé avec chaque niveau d'autorisation (un admin ne voit jamais ce qu'il n'a pas le droit de voir).

### Phase 7 – Qualité et phase pilote
- Revue de sécurité complète (liste de `security.md`).
- Tests de bout en bout de tous les parcours, tests sur la liste de téléphones, nouveau test de charge.
- **Phase pilote de 1 à 2 semaines** avec quelques employés au bureau ; correction de tout ce qui remonte (suivi des erreurs + journaux).
- **Validation** : aucun bug connu ouvert ; votre accord pour la mise en production.

### Phase 8 – Mise en production sur le serveur de l'entreprise
- Déploiement Docker : API, file d'attente, planificateur, HTTPS, nom de domaine.
- Base de production (Supabase ou PostgreSQL sur le serveur), stockage, sauvegardes automatiques testées.
- Publication de l'application : Android (fichier d'installation ou Play Store), iOS (App Store / TestFlight).
- Surveillance : suivi des erreurs, alerte si une tâche planifiée n'a pas tourné, contrôle de disponibilité.
- Création du premier super admin, configuration initiale, invitation des employés.

## 6. Stratégie de tests (objectif zéro bug connu)

| Niveau | Ce qui est testé | Quand |
|---|---|---|
| Tests unitaires | Règles métier : retard, distance et précision, vérification Face ID (passkey) obligatoire au check-in, minuit, périodes semaine / mois du 26 au 26, jours off / congés / fériés, blocages, autorisations | À chaque envoi de code |
| Tests d'API | Chaque route, chaque rôle, chaque cas d'erreur | À chaque envoi de code |
| Tests de concurrence | Double clic, automatique + manuel en même temps, 200 employés simultanés | Phases 3, 7 |
| Tests de bout en bout | Inscription, connexion, check-in, tâches, clients, rapports, administration | Phases 2 à 7 |
| Tests sur appareils réels | Voir section 7 | Phases 4, 7 |
| Suivi en production | Erreurs du serveur et des téléphones rattachées au compte | Dès la phase pilote |

Les heures (17:00, 16:30, 00:00, le 26…) sont testées avec une **horloge simulée**, pour vérifier chaque échéance sans attendre.

## 7. Appareils de test

À réunir pour les phases 4 et 7 (prêtés par des employés si besoin) :
- 2 à 3 Android d'entrée de gamme courants au Ghana (Tecno, Infinix, itel) ;
- 1 Samsung (série A) ;
- **plusieurs iPhone** (la majorité des employés), dont un ancien modèle avec iOS 16.4 ou plus ;
- navigateurs : Chrome, Safari, et un lien ouvert depuis WhatsApp.

## 8. Ce dont j'ai besoin de votre part

| Élément | Pour quelle phase |
|---|---|
| Projet **Supabase** (base + stockage) et ses accès | Phase 0 |
| Compte **Resend** + **nom de domaine** d'envoi des emails (ex. `@assistanceghana.com`), avec accès aux réglages DNS pour le vérifier | Phase 0–1 |
| Compte **Vercel** relié au dépôt frontend | Phase 0 |
| Hébergeur de test pour le backend (Render, Railway ou Koyeb) | Phase 0 |
| **Logo** en haute qualité (SVG ou PNG) | Phase 2 |
| **Coordonnées GPS exactes** du bureau (relevées sur place) | Phase 3 |
| Liste des **jours fériés** | Phase 3 |
| Caractéristiques du **serveur de l'entreprise** (système, accès, domaine) | Phase 8 |

Les clés secrètes ne doivent **jamais** être envoyées dans la conversation : elles seront ajoutées dans les réglages sécurisés de l'environnement ou des hébergeurs.

## 9. Décisions à prendre avant de commencer

1. ~~iOS / application native~~ → **décidé : PWA** (majorité d'iPhone), pas de Capacitor.
2. ~~Module de géolocalisation en arrière-plan~~ → **sans objet** : check-in manuel uniquement (GPS + Face ID).
3. ~~Ordre de livraison~~ → **décidé : phase 4 avant la phase 5.**
4. ~~Mise en ligne~~ → **décidé : par étapes** (pointage manuel en service dès la fin de la phase 3, puis ajouts).
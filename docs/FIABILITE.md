# Fiabilité – bugs de l'ancienne version et risques de la nouvelle

Complète `SPECS_REFONTE.md` et `security.md`.
Objectif : identifier **avant le plan de développement** tout ce qui a causé des bugs et tout ce qui peut en causer dans la nouvelle version, et décider comment l'éviter.

---

## Partie A – Pourquoi l'ancienne version avait des bugs

Analyse du code actuel (`backend_Attendance`, `frontend_Attendance`).

### A1. « Plusieurs employés ne peuvent pas faire le check-in en même temps »

| Cause | Preuve dans le code | Effet |
|---|---|---|
| **Ancien index unique `employee + date`** resté dans MongoDB après le renommage du champ `employee` en `userId`. Tous les enregistrements avaient `employee = vide`, donc **un seul check-in possible par jour pour toute l'entreprise** : le 2e, le 3e… recevaient « A attendance record already exists ». | Commits « fix check in » des 19–20 mai, et code de `server.js` qui supprime au démarrage « tout index qui contient employee ». | Le matin, seul le premier employé arrivait à pointer. |
| **Hébergement gratuit Render** qui se met en veille après ~15 min sans activité. Le premier appel du matin met 30 à 60 s à réveiller le serveur ; le frontend abandonne au bout de 15 s. | `nginx.conf` → `backend-attendance-osif.onrender.com` ; `axiosClient.ts` → `timeout: 15000`. | Les employés arrivés ensemble le matin ont tous une erreur en même temps. |

### A2. « Certains n'arrivent plus à utiliser l'application »

| Cause | Preuve | Effet |
|---|---|---|
| **Déconnexion automatique après 30 min** basée sur les mouvements de **souris** et le **clavier**. Sur téléphone, il n'y a ni souris ni clavier physique : l'employé est déconnecté toutes les 30 min, même en utilisant l'appli. | `hooks/useAuth.ts` : `mousemove`, `keypress`. | Déconnexions inexpliquées sur mobile. |
| **Jeton de connexion valable 8 h**. Connexion à 8:00 → expiré à 16:00 → le check-out de 17:00 échoue et l'employé est renvoyé à la page de connexion. | `.env.example` : `JWT_EXPIRES_IN=8h` ; `axiosClient.ts` vide la session sur toute erreur 401. | Check-out impossible en fin de journée. |
| **Lien ouvert depuis WhatsApp / Facebook** : ces navigateurs intégrés bloquent souvent la géolocalisation. | Aucune détection dans le code. | « Ça ne marche pas » pour certains téléphones seulement. |

### A3. « Le check-in échoue alors que je suis au bureau »

| Cause | Preuve | Effet |
|---|---|---|
| La **précision du GPS** n'est pas prise en compte : une position avec 200 m d'imprécision (fréquent en intérieur) est traitée comme exacte et rejetée « hors périmètre ». | `checkIn` n'utilise que latitude / longitude ; `accuracy` est ignorée. | Refus aléatoires. |
| GPS haute précision avec **10 s maximum**, sans nouvel essai. En intérieur, le GPS met souvent plus de 10 s. | `hooks/useGeolocation.ts`. | Erreur « Timeout ». |
| Le texte de la page annonce 60 m alors que le serveur peut être configuré autrement. | `CheckIn.tsx` (texte en dur). | Incompréhension. |

### A4. « L'heure n'est pas correcte »

| Cause | Preuve | Effet |
|---|---|---|
| La **date** du jour est calculée en heure UTC, l'**heure** de pointage en heure locale du serveur ; elles ne sont pas forcément cohérentes selon l'hébergeur. | `attendanceController.js` : `toISOString()` vs `toTimeString()`. | Pointage enregistré sur le mauvais jour ou à la mauvaise heure. |
| L'horloge affichée sur la page est celle **du téléphone**, l'heure enregistrée celle **du serveur**. Un téléphone mal réglé affiche une heure différente de celle enregistrée. | `CheckIn.tsx` : `new Date()`. | « L'heure n'est pas la bonne ». |
| La date des tâches est calculée **par le téléphone**. | `Tasks.tsx` : `new Date().toISOString()`. | Tâches qui « disparaissent » si la date du téléphone est fausse. |
| Le seuil de retard a été changé dans le code (8:30 → 8:05) sans recalculer les jours passés. | Commit « fix late time » du 26 mai. | Rapports incohérents sur le mois. |

### A5. « Pas de notification »
- Il n'existe **aucune notification** : seulement un message temporaire à l'écran qui disparaît.

### A6. « Les rapports ne sont pas bien faits »
- Le tableau de bord admin **charge toutes les données** de la base dans le téléphone et fait les calculs côté navigateur : lent, et différent selon le fuseau du téléphone.
- Les employés supprimés gardent leurs pointages, mais sans nom associé : lignes vides ou erreurs.
- Les heures, les retards et les dates sont stockés en texte libre (« 08:12:00 ») sans fuseau horaire.

**Conclusion** : la plupart des bugs ne venaient pas des téléphones mais de **la gestion du temps, des sessions, de la géolocalisation et de l'hébergement**. La nouvelle version doit traiter chacun de ces points dès la conception.

---

## Partie B – Règles de conception pour éviter ces bugs

### B1. Pointages simultanés (10, 20, 100 employés au même moment)
- Chaque employé a **sa propre session** ; aucune action d'un employé ne bloque celle d'un autre.
- En base : **une contrainte d'unicité « un pointage par employé et par jour »**, et une écriture qui ne peut pas créer de doublon même si l'employé appuie deux fois ou si le check-in automatique et le manuel arrivent en même temps.
- Les traitements lents (emails, notifications) sont mis **en file d'attente** et ne ralentissent jamais le check-in.
- Hébergement **qui ne se met jamais en veille**, et connexions à la base gérées par un « pooler » (Supabase en fournit un).
- **Critère de validation** : test de charge avec **200 check-ins simultanés** sans aucune erreur avant la mise en production.

### B2. Une seule source de vérité pour l'heure
- **Toutes** les heures et dates viennent **du serveur**, en fuseau **Africa/Accra**, stockées avec fuseau horaire.
- Le téléphone n'envoie jamais d'heure ni de date ; l'horloge affichée est synchronisée sur l'heure du serveur.
- Le « jour de travail » est calculé une seule fois, côté serveur.
- Quand un paramètre change (heure de retard…), il ne s'applique **qu'à partir de sa date d'effet** : l'historique n'est jamais réécrit, et chaque pointage garde la règle utilisée.

### B3. Sessions qui ne coupent pas en pleine journée
- Session longue sur l'appareil (renouvelée automatiquement tant que l'employé utilise l'appli), qui ne peut pas expirer entre le check-in et le check-out.
- Pas de déconnexion basée sur la souris ou le clavier.
- Une erreur réseau ou serveur ne déconnecte **jamais** l'utilisateur : seule une session réellement invalide (compte bloqué, mot de passe changé) le fait.

### B4. Géolocalisation fiable
- Le serveur reçoit la position **et sa précision**. Règle : on valide si la position est dans le périmètre **en tenant compte de la précision**, et on redemande la position automatiquement tant qu'elle est trop imprécise.
- Plusieurs essais automatiques, avec un indicateur à l'écran (« Searching for your location… accuracy 35 m »).
- Messages clairs pour chaque cas : GPS désactivé, autorisation refusée, position imprécise, hors périmètre (avec la distance), pas de connexion.
- Détection des navigateurs intégrés (WhatsApp, Facebook) : message demandant d'ouvrir l'application ou Chrome / Safari.
- Les rayons et textes affichés viennent de la configuration, jamais écrits en dur.

### B5. Notifications fiables
- Notification enregistrée **en base** (centre de notifications dans l'appli) **+** push sur le téléphone **+** email selon le cas. Si le push échoue, la notification reste visible dans l'appli.

### B6. Tâches planifiées fiables (17:00, 16:30, vendredi, 00:00, le 26)
- Chaque tâche planifiée est **rattrapée** si le serveur était indisponible à l'heure prévue, et ne s'exécute **jamais deux fois** (pas de double email).
- Surveillance : alerte au super admin si une tâche planifiée n'a pas tourné.

### B7. Rapports calculés côté serveur
- Tous les calculs (retards, clients, services, envois) sont faits par le serveur, avec pagination. Le téléphone ne reçoit que le résultat.
- Les employés supprimés restent dans l'historique avec leur nom.

### B8. Qualité et tests (objectif : zéro bug connu à la mise en production)
- **Tests automatiques** des règles métier : retard, périmètre, check-in automatique, check-out à 00:00, périodes hebdo / mensuelles, blocages de connexion, autorisations.
- **Tests de bout en bout** des parcours principaux (inscription → code → connexion → check-in → tâches → rapport).
- **Test de charge** (B1).
- **Tests sur vrais téléphones** : Android d'entrée de gamme (Tecno, Infinix, itel, Samsung A), iPhone, Chrome, Safari.
- **Environnement de test** séparé de la production.
- **Suivi des erreurs en production** (outil d'alerte automatique) pour voir chaque bug avant que les employés ne le signalent.
- **Phase pilote** de 1 à 2 semaines avec quelques employés avant le déploiement à tous.
- **Outils de correction pour l'admin** : corriger un pointage, avec motif, enregistré dans le journal d'audit.

---

## Partie C – Cas de figure à risque dans les nouvelles spécifications

Ce sont les points des spécifications qui, sans décision claire, produiront des bugs.

### C1. Check-in automatique
1. **Heure enregistrée** : l'employé arrive à 08:05, le check-in automatique se fait 10 min plus tard (08:15). Il est donc **en retard** à cause du délai. Faut-il enregistrer l'heure **d'entrée dans la zone** (08:05) ou l'heure du check-in automatique (08:15) ?
2. **En dehors des heures de travail** : un employé qui passe devant le bureau un dimanche, un jour férié ou à 6:00 serait pointé automatiquement. Proposition : check-in automatique **seulement les jours ouvrés, dans une plage horaire** (ex. 06:30–12:00), configurable.
3. **Après un check-out** : si l'employé revient au bureau après son check-out, pas de nouveau check-in automatique (un seul pointage par jour). D'accord ?
4. **Téléphones Android qui tuent les applis en arrière-plan** (Tecno, Infinix, Xiaomi… très répandus) : le check-in automatique peut ne pas se déclencher. Prévoir un guide au premier lancement pour désactiver l'économie de batterie pour l'appli. Le check-in manuel reste toujours possible.
5. **Pas d'internet** au moment du check-in : le check-in exige une connexion (l'heure vient du serveur). Proposition : l'appli réessaie automatiquement dès que la connexion revient, en gardant l'heure à laquelle l'employé était dans la zone, marquée « enregistré hors connexion ». D'accord ?
6. **Même compte sur deux téléphones** : autoriser, ou un seul appareil par employé ?

### C2. Check-out
7. Le check-out **demande-t-il aussi la géolocalisation** (être au bureau), ou peut-il se faire de n'importe où ?
8. Check-out avant 17:00 avec motif : si l'employé part avant 17:00 **sans** faire de check-out, à 00:00 la journée est « check-out oublié ». Pas de motif demandé dans ce cas. D'accord ?

### C3. Accès bloqué sans check-in
9. **Missions extérieures** : un employé qui part directement chez un client le matin ne peut pas faire le check-in au bureau, donc ne peut rien faire dans l'appli de la journée (ni tâches, ni clients). Prévoir un « check-in mission » validé par un admin, ou un check-in manuel par un admin ?
10. **Congés, absences, jours fériés** : ces jours ne doivent pas compter comme absences dans les rapports. Faut-il gérer les congés dans l'appli (déclarés par l'admin) ?

### C4. Rapports
11. **Qui doit envoyer le rapport du vendredi** : seulement les employés et stagiaires actifs (pas les admins, ni les comptes bloqués, en attente ou en congé) ? C'est ce qui détermine « tout le monde a envoyé ».
12. **Vendredi férié ou employé absent** : le rapport peut-il être envoyé un autre jour ? Compte-t-il comme « en retard » ?
13. **Ré-envoi** : l'employé peut-il remplacer son PDF après l'avoir envoyé ?
14. **Taille des emails** : envoyer tous les PDF en pièces jointes à 17:00 peut dépasser la limite d'un email (40 Mo chez Resend) avec beaucoup d'employés. Proposition : l'email contient la liste et des **liens sécurisés** vers chaque PDF (et les PDF en pièce jointe tant que la taille le permet). D'accord ?
15. **Rapport mensuel du 26 au 26** : rappel, le 26 est compté dans deux rapports (un retard le 26 apparaît deux fois). Choix déjà validé, à garder ?
16. **Employé arrivé ou parti en cours de mois** : il apparaît dans le rapport mensuel avec les seuls jours où il était actif. D'accord ?

### C5. Connexion et sécurité
17. **Blocage malveillant** : quelqu'un qui connaît l'email d'un employé peut faire 15 essais faux et **verrouiller son compte** ; l'employé ne peut plus pointer jusqu'à l'intervention d'un admin. Proposition : compter les échecs **par appareil** en plus du compte, et ne verrouiller le compte que si les échecs viennent d'un appareil inconnu… ou accepter le risque. Votre choix ?
18. **Super admin dépendant de l'email** : si l'email ou Resend est en panne, le super admin ne peut pas se connecter. Recommandation : **au moins deux super admins** en permanence.

### C6. Exploitation
19. **Données existantes** : faut-il **migrer** les données de l'ancienne version (comptes, pointages, tâches) depuis MongoDB vers PostgreSQL, ou repartir de zéro ?
20. **Mises à jour de l'application installée** : prévoir une **version minimale obligatoire** (l'appli demande la mise à jour si elle est trop ancienne), pour éviter que des employés gardent une vieille version qui ne fonctionne plus avec le serveur.
21. **Hébergement de production** : Supabase pour la base ; il faut aussi un hébergeur pour le backend Laravel qui **ne se met pas en veille** (offre payante, quelques dollars par mois). D'accord ?

---

## Partie D – Décisions prises

| Point | Décision |
|---|---|
| C1.1 Heure du check-in automatique | **Heure d'arrivée dans la zone** |
| C1.2 Check-in automatique hors horaires | **Seulement les jours ouvrés, de 06:30 à 12:00** (configurable) |
| C3.9 Missions extérieures | **Check-in mission** validé par un admin autorisé |
| C3.10 Congés / jours fériés | **Gérés dans l'application**. En plus : l'employé choisit lui-même ses **jours off** (jusqu'à 2 par semaine) et ses jours de **télétravail**, non comptés comme absences |
| C6.19 Données existantes | **On repart de zéro** |
| C6.21 Hébergement | **Tests** : base sur Supabase, frontend sur Vercel, backend Laravel sur un hébergeur PHP/Docker. **Production** : serveur de l'entreprise après validation |

### Hébergement – précisions
- **Supabase ne peut pas faire tourner le backend Laravel** : Supabase fournit la base PostgreSQL, le stockage de fichiers et des fonctions en TypeScript, mais n'exécute pas de PHP. Pour les tests, le backend doit tourner ailleurs : Render (Docker), Railway, Fly.io ou Koyeb, par exemple.
  - Une offre **gratuite** qui se met en veille suffit pour tester les fonctionnalités, mais **pas pour le test de charge** ni pour les tâches planifiées (17:00, 00:00…) : ces tests-là se feront sur une offre qui ne dort pas, ou directement sur le serveur de l'entreprise.
- **Frontend sur Vercel** : adapté.
- **Serveur de l'entreprise (production)** doit fournir : PHP 8.3+, une tâche planifiée système (cron) chaque minute pour le planificateur Laravel, un processus permanent pour la file d'attente (emails, notifications), HTTPS avec un nom de domaine, et l'accès à PostgreSQL (Supabase ou installé sur le serveur).
- Le code sera prêt à déployer **avec Docker**, pour passer de l'hébergement de test au serveur de l'entreprise sans modification.

### Décisions complémentaires

| Point | Décision |
|---|---|
| C5.17 Blocage malveillant | **Échecs comptés par appareil** (voir `security.md`) |
| Jours off / télétravail | Fixés par le **contrat** ; l'employé les sélectionne **une fois** ; ensuite **seul un admin** peut les modifier ou les retirer |
| C1.5 Hors connexion | **Pas d'enregistrement différé** : notification demandant de se connecter ; l'heure retenue est celle où le check-in atteint le serveur (le retard dû à la connexion est à la charge de l'employé) |
| C1.3 Retour après check-out | Pas de nouveau check-in automatique le même jour |
| C2.7 Géolocalisation au check-out | **Non obligatoire** ; la **distance au bureau** est enregistrée et affichée |
| C4.11 Qui envoie le rapport du vendredi | Employés et stagiaires **actifs et attendus** ce jour-là |
| C4.14 Taille des emails | PDF max **10 Mo** ; pièces jointes jusqu'à **~30 Mo** par email, au-delà **liens sécurisés** |
| Nouveau | **Journaux d'activité** complets consultables par le super admin (voir `SPECS_REFONTE.md` 9 bis) |
| Jour de télétravail | **Check-in télétravail en un clic**, sans contrôle de position, même calcul de retard |
| Application | **PWA** au lieu d'une application native (majorité d'iPhone). Les points C1.4 (applis tuées en arrière-plan) et C6.20 (version minimale de l'application) deviennent sans objet ; la PWA propose elle-même la mise à jour |
| Check-in automatique | Repose sur le **Wi-Fi du bureau** (adresse IP) au lieu du GPS en arrière-plan : rappel 5 min et check-in 10 min après la première détection, heure = première détection |
| Check-in manuel | **GPS (50 m)**, avec repli sur le Wi-Fi du bureau si le GPS échoue |

### Choix par défaut retenus (modifiables)

Pour les points non tranchés, voici ce qui sera appliqué sauf avis contraire :

| Point | Choix par défaut |
|---|---|
| C1.4 Applis tuées en arrière-plan | Sans objet (PWA) |
| C1.6 Même compte sur plusieurs appareils | **Autorisé**, chaque appareil visible dans les journaux ; l'admin peut déconnecter un appareil |
| C2.8 Départ avant 17:00 sans check-out | Journée marquée « check-out oublié » à 00:00, pas de motif demandé |
| C4.12 Vendredi férié / employé absent | Pas attendu ce vendredi ; il peut envoyer son rapport le jour ouvré suivant, sans être compté en retard |
| C4.13 Remplacement du PDF | Autorisé ; l'ancienne version est conservée et le remplacement apparaît dans les journaux |
| C4.15 Rapport mensuel du 26 au 26 | Conservé tel que décidé |
| C4.16 Employé arrivé / parti en cours de mois | Apparaît avec ses seuls jours actifs |
| C5.18 Dépendance à l'email du super admin | Recommandation affichée tant qu'il n'existe qu'un seul super admin |
| C6.20 Mises à jour de l'application | Version minimale obligatoire : l'application demande la mise à jour si elle est trop ancienne |

## Questions encore ouvertes

Aucune. Les spécifications sont fixées ; voir `PLAN_DEVELOPPEMENT.md`.

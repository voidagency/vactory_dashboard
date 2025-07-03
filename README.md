# README - Configuration du Back Office et Dashboard

## 1. Administer Dashboard

### Dashboard Global Settings
Permet de contrôler l’affichage et les éléments visibles dans le sidebare du dashboard.

- **Logo**  
  Choisir le logo de la plateforme.  
  *Formats acceptés :* jpg, jpeg, png, webp.  
  *Action :* Upload d’une image.

- **Menu Settings**  
  Sélectionner via un menu déroulant, le menu dont les pages seront affichées dans le sidebare du dashboard.  
  *Action :* Choisir le nom machine (machine name) du menu, ce qui contrôle les pages visibles dans l’élément « Pages » du sidebare.

- **Webforms to display in dashboard**  
  Choisir les formulaires que vous souhaitez voir et gérer dans le sidebare du dashboard.  
  *Action :* Cocher les formulaires à afficher.

- **Content types**  
  Choisir les types de contenus que vous souhaitez voir et gérer dans le sidebare du dashboard.  
  *Action :* Cocher les types de contenus à afficher.

- **Taxonomies to display on dashboard sidebar**  
  Choisir les taxonomies visibles dans le sidebare du dashboard.  
  *Action :* Cocher les taxonomies à afficher.

---

## 2. Advanced Dashboard Help

Aide à l’utilisation du dashboard.

- **Video tutoriel URL**  
  URL d’une vidéo expliquant comment utiliser le nouveau Back Office.

- **User manual**  
  Manuel utilisateur au format PDF, détaillant les fonctionnalités et l’utilisation du dashboard.

---

## 3. Advanced Dashboard Support

Cette section permet de renseigner un contact de support (équipe TMA, équipe de développement, etc.) qui peut aider le client en cas de bug ou de blocage.

- Champs à renseigner :  
  - Prénom  
  - Nom  
  - Poste  
  - Adresse mail  
  - Téléphone  
  - Image (photo de contact)

---

## 4. Content Types Configuration

Permet de contrôler l’affichage des taxonomies associées à chaque type de contenu.

- *Action :* Cocher les taxonomies à afficher pour chaque type de contenu.

---

## 5. Redmine Configuration

Configuration pour afficher les tickets Redmine assignés à l’utilisateur authentifié (même email utilisé dans Redmine et dans le BO).

- **Project identifier**  
  Identifiant du projet Redmine (extrait de l’URL Redmine).  
  Exemple :  
  URL : `https://redmine3.void.fr/projects/vactory-4-next`  
  Identifiant : `vactory-4-next`

- **API Key**  
  Clé API Redmine pour accéder aux tickets (nDwB38RsSPex).

- **Redmine URL**  
  URL de l’API Redmine (modifiable selon le serveur).  
  Exemple : `https://redmine-api.leserveurdetest.com/issues`

---

## 6. SSL Domaine Configuration

Permet de vérifier le statut SSL d’un domaine.

- **Domain name**  
  Saisir le nom de domaine à vérifier.

- **Informations récupérées**  
  - Host  
  - Issuer  
  - Valid till (date d’expiration)  
  - Days left (jours restants)  
  - Certificate valid (validité du certificat)

- **Bouton "Mettre à jour les infos SSL"**  
  Lance un appel API pour récupérer les dernières informations SSL.

---

# Notes

- Toutes ces configurations sont accessibles via le Back Office dans les sections dédiées.  
- Assurez-vous d’avoir les droits administratifs pour modifier ces paramètres.

---

Ce README permet aux administrateurs et développeurs de comprendre rapidement quelles configurations sont nécessaires pour faire fonctionner et personnaliser le dashboard et les fonctionnalités associées dans le Back Office.

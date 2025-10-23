function editUserPage(props) {
  return {
    userId: props.userId,
    user: JSON.parse(props.userData ?? '') ?? {},
    roles: JSON.parse(props.roles ?? '') ?? [],
    errors: {},
    isSaving: false,
    async save() {
      this.errors = {};
      this.isSaving = true;
      if (!this.user.name || this.user.name.length < 4) {
        this.errors.name = 'Le nom doit comporter au moins 4 caractères.';
      }
      if (!this.user.email || !this.user.email.match(/^\S+@\S+\.\S+$/)) {
        this.errors.email = 'Adresse email invalide.';
      }
      if (Object.keys(this.errors).length > 0) {
        return;
      }
      try {
        const rolesArray = Array.isArray(this.user.roles) ? [...this.user.roles] : [];
        const userToSave = {
          name: this.user.name,
          email: this.user.email,
          status: this.user.status,

        };

        // Only include roles if roles object is not empty.
        if (Object.keys(this.roles).length > 0) {
          userToSave.roles = rolesArray;
        }
        
        const response = await fetch(drupalSettings.vactoryDashboard.editPath, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(userToSave),
        });
        this.isSaving = false;
        if (response.ok) {
          window.location.href = drupalSettings.vactoryDashboard.listPath;
        } else {
          const errorData = await response.json();
          console.error('Erreur backend :', errorData.message);
          alert('Erreur lors de la mise à jour : ' + errorData.message);
        }
      } catch (error) {
        console.error('Erreur lors de la requête :', error);
        this.isSaving = false;
        alert('Erreur réseau.');
      }
    },

  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('editUserPage', editUserPage);
});

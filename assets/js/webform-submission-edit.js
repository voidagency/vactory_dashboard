function submission(id, submission_id) {
  return {
    id: id,
    submission_id: submission_id,
    fields: [],
    loading: true,
    excludedFields: ['csrfToken', 'csrf_token', 'g-recaptcha-response', 'in_draft'],
    notification: {
      show: false,
      error: false,
      message: '',
    },
    pages: [],
    async loadSubmission() {
      try {
        const response = await fetch(drupalSettings.vactoryDashboard.dataPath);
        const data = await response.json();
        this.fields = data;
      } catch (error) {
        console.error('Error loading submission:', error);
      } finally {
        this.loading = false;
      }
    },
    flattenFormData(formData) {
      const result = {};
      for (const key in formData) {
        const value = formData[key];
        if (typeof value === 'object' && value !== null && 'value' in value) {
          result[key] = value.value;
        } else {
          result[key] = value;
        }
      }
      return result;
    },
    async editSubmission() {
      try {
        const response = await fetch(drupalSettings.vactoryDashboard.editPath, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(this.flattenFormData(this.fields)),
        });
        const result = await response.json();
        this.setNotification(false, Drupal.t('La soumission a été modifié'));
      } catch (error) {
        this.setNotification(true, Drupal.t('Une erreur est survenue lors de la modification de la soumission'));
      }
    },
    init() {
      this.loadSubmission();
    },
  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('submission', submission);
});

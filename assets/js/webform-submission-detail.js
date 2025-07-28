function submission(id, submission_id) {
  return {
    id: id,
    submission_id: submission_id,
    fields: [],
    loading: true,
    excludedFields: ['csrfToken', 'csrf_token', 'g-recaptcha-response', 'in_draft'],
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
    normalizeValue(value) {
      if (!value || value === null || value === undefined || value === '') {
        return '___';
      }

      if (Array.isArray(value)) {
        return value.length ? value.join(', ') : '___';
      }

      return value;
    },
    init() {
      this.loadSubmission();
    },
  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('submission', submission);
});

function translationsData() {
  return {
    translations: [],
    selectedTerms: [],
    loading: true,
    error: null,
    search: '',
    showOnlyNx: false,
    showAddModal: false,
    currentPage: 1,
    totalPages: 1,
    columns: [],
    langCodes: [],
    keywords: '',
    modifiedTranslations: new Map(),
    editing: {},
    total: 0,
    limit: Alpine.store('limit'),
    pages: [],

    toggleAll() {
      if (this.selectedTerms.length === this.translations.length) {
        this.selectedTerms = [];
      } else {
        this.selectedTerms = this.translations.map(term => ({ source: term.source, context: term.context }));
      }
    },

    generatePageNumbers(currentPage, totalPages) {
      const pages = [];
      const maxVisiblePages = 5;
      let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
      let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
      if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
      }
      for (let i = startPage; i <= endPage; i++) {
        pages.push(i);
      }
      return pages;
    },

    async fetchTranslations(page = 1) {
      this.loading = true;
      this.error = null;
      this.currentPage = page;

      const params = new URLSearchParams({
        page: page,
        limit: this.limit,
        search: this.search,
        nx_only: this.showOnlyNx ? '1' : '0',
      });

      try {
        const response = await fetch(`${drupalSettings.vactoryDashboardTranslations.data}?${params.toString()}`);
        if (!response.ok) {
          throw new Error(Drupal.t('Une erreur est survenue lors du chargement des traductions'));
        }

        const data = await response.json();
        this.translations = data.data;
        this.total = data.total;
        this.totalPages = data.pages;
        this.pages = this.generatePageNumbers(data.page, data.pages);
      } catch (error) {
        console.error('Error loading translations:', error);
        this.error = error.message;
        this.translations = [];
      } finally {
        this.loading = false;
      }
    },

    async fetchLanguages() {
      try {
        const response = await fetch(drupalSettings.vactoryDashboardTranslations.languages);
        if (!response.ok) {
          throw new Error(Drupal.t('Une erreur est survenue lors du chargement des traductions'));
        }
        const data = await response.json();
        this.columns = data['lang_names'];
        this.langCodes = data['lang_codes'];
      } catch (error) {
        console.error('Error loading languages:', error);
      }
    },

    async deleteTranslation(source, context) {
      if (!confirm(Drupal.t('Êtes-vous sûr de vouloir supprimer cette traduction ?'))) {
        return;
      }

      try {
        const response = await fetch(drupalSettings.vactoryDashboardTranslations.delete, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ source, context }),
        });

        if (!response.ok) {
          this.setNotification(true, Drupal.t('Une erreur est survenue lors de la suppression des traduction'));
          throw new Error(Drupal.t('Une erreur est survenue lors de la suppression de la traduction'));
        }

        await this.fetchTranslations(1);
        this.setNotification(false, Drupal.t('Les traductions ont été suprimmé avec succés'));
      } catch (error) {
        this.error = error.message;
        console.error('Error deleting translation:', error);
        this.setNotification(true, Drupal.t('Une erreur est survenue lors de la suppression des traduction'));
      }
    },

    toggleTermSelection(event, translation) {
      if (event.target.checked) {
        this.selectedTerms.push({ source: translation.source, context: translation.context });
      } else {
        this.selectedTerms = this.selectedTerms.filter(
          t => !(t.source === translation.source && t.context === translation.context)
        );
      }
    },

    async deleteTranslationBulk() {
      if (!confirm(Drupal.t('Êtes-vous sûr de vouloir supprimer les termes sélectionnés ?'))) {
        return;
      }

      try {
        const response = await fetch(drupalSettings.vactoryDashboardTranslations.bulkDelete, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ selectedTerms: this.selectedTerms }),
        });

        if (response.ok) {
          await this.fetchTranslations(1);
          this.setNotification(false, Drupal.t('Les traductions ont été suprimmé avec succés'));
        } else {
          this.setNotification(true, Drupal.t('Une erreur est survenue lors de la suppression des traduction'));
        }
      } catch (error) {
        console.error('Error deleting translations:', error);
        this.error = error.message;
      } finally {
        this.selectedTerms = [];
      }
    },

    async importKeywords() {
      const response = await fetch(drupalSettings.vactoryDashboardTranslations.importFront, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ keywords: this.keywords }),
      });
      const result = await response.json();
      if (result.status === 'ok') {
        await this.fetchTranslations(1);
        this.setNotification(false, Drupal.t('Les termes ont été importé avec success'));
        this.showAddModal = false;
      } else {
        this.setNotification(true, Drupal.t("L import des termes a échoué"));
        this.showAddModal = false;
      }
    },

    saveRow(translation) {
      const raw = Alpine.raw(translation.translations);
      const plainTranslations = Object.fromEntries(Object.entries(raw));
      this.editing = {};

      fetch(drupalSettings.vactoryDashboardTranslations.edit, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          source: translation.source,
          context: translation.context,
          translations: plainTranslations,
        }),
      })
        .then(response => {
          if (!response.ok) throw new Error('Network response was not ok');
          return response.json();
        })
        .catch(error => {
          console.error('Save failed:', error);
        });
    },

    init() {
      this.fetchLanguages();
      this.fetchTranslations(1);
    },
  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('translationsData', translationsData);
});

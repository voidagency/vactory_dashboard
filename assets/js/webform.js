function webformTable(id) {
  return {
    id: id,
    itemToDelete: null,
    showDeleteModal: false,
    webforms: [],
    loading: true,
    exportLoading: false,
    exportTotalPages: 0,
    exportCurrentPage: 0,
    exportProgressPercent: 0,
    exportInterval: null,
    exportMessage: '',
    currentPage: 1,
    totalPages: 1,
    formID: 'contact',
    selectedSubmissions: [],
    filters: {
      search: '',
    },
    excludedFields: [
      'id',
      'ip',
      'csrfToken',
      'completed',
      'csrf_token',
      'g-recaptcha-response',
      'in_draft',
      'created',
      'webform_id',
      'uid',
      'remote_addr',
    ],
    pages: [],
    toggleAll() {
      if (this.selectedSubmissions.length === this.webforms.length) {
        this.selectedSubmissions = [];
      } else {
        this.selectedSubmissions = this.webforms.map(user => user.id);
      }
    },
    async deleteSelected(id = null) {
      try {
        const response = await fetch(drupalSettings.vactoryDashboard.deletePath, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(
            { submissionIds: this.itemToDelete ? [this.itemToDelete] : this.selectedSubmissions },
          ),
        });

        if (response.ok) {
          this.setNotification(false, Drupal.t("La soumission est supprimée avec succès"));
          const itemsToDeleteCount = this.itemToDelete ? 1 : this.selectedSubmissions.length;
          if (this.currentPage > 1 && (this.webforms.length - itemsToDeleteCount) === 0) {
            this.currentPage -= 1;
          }
          await this.$nextTick();
          await this.loadWebforms(this.currentPage);
          this.selectedSubmissions = [];
          this.showDeleteModal = false;
          this.itemToDelete = null;
        }
      } catch (error) {
        console.error(error);
        this.setNotification(true, Drupal.t("Une erreur est survenue lors de la suppression de la soumission"));
        this.showDeleteModal = false;
      } finally {
        this.uncheck();
      }
    },
    async loadWebforms(page = 1) {
      this.currentPage = page;
      const params = new URLSearchParams({ page: page, limit: Alpine.store('limit'), search: this.filters.search });

      try {
        const endpointUrl = drupalSettings.vactoryDashboard.dataPath;
        const response = await fetch(`${endpointUrl}?${params.toString()
        }`);
        const data = await response.json();

        this.webforms = data.data;
        this.totalPages = data.pages;
        this.formID = data.form_id;
        this.pages = this.generatePageNumbers(data.page, data.pages);

        this.dynamiColumns = this.dynamicValues = [];
        if (this.webforms.length > 0 && this.webforms[0].data) {
          const entries = Object.entries(this.webforms[0].data);
          const filtered = entries.filter(([key, _]) => !this.excludedFields.includes(key));

          this.dynamiColumns = filtered.map(([key]) => key);
          this.dynamicValues = this.webforms.map(wf => {
            return this.dynamiColumns.map(key => wf.data[key] ?? null);
          });
        }
      } catch (error) {
        console.error('Error loading webforms:', error);
      } finally {
        this.loading = false;
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
    normalizeValue(value) {
      if (!value || value === null || value === undefined || value === '') {
        return '___';
      }

      if (Array.isArray(value)) {
        return value.length ? value.join(', ') : '___';
      }

      const str = String(value);
      return this.highlightMatch(str, this.filters.search);
    },
    highlightMatch(value, search) {
      if (!value || !search) {
        return String(value || '');
      }

      const escapedSearch = search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const regex = new RegExp(`(${escapedSearch})`, 'gi');

      return String(value).replace(regex, '<mark>$1</mark>');
    },
    normalizeColumn(column) {
      return column.replace(/[_-]/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
    },
    download() {
      if (this.exportLoading) {
        return;
      }
      this.exportLoading = true;
      this.exportProgressPercent = 0;
      this.exportMessage = 'Démarrage de l\'export...';

      // Démarrer export batch côté backend
      fetch(`/batch-export/start/${this.formID}`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
          this.exportMessage = data.message || 'Export démarré';

          // Lancer polling de progression
          this.exportInterval = setInterval(() => {
            fetch(`/batch-export/process/${this.formID}`)
              .then(res => res.json())
              .then(progressData => {
                if (progressData.error) {
                  this.exportMessage = progressData.error;
                  this.exportLoading = false;
                  clearInterval(this.exportInterval);
                  return;
                }
                this.exportProgressPercent = progressData.progress || 0;
                this.exportMessage = `Progression : ${this.exportProgressPercent}% (${progressData.done}/${progressData.total})`;

                if (progressData.status === 'finished') {
                  this.exportMessage = 'Export terminé. Téléchargement en cours...';
                  clearInterval(this.exportInterval);
                  this.downloadFile();
                  // Réinitialiser la barre de progression après le téléchargement
                  setTimeout(() => {
                    this.exportLoading = false;
                    this.exportProgressPercent = 0;
                  }, 2000); // Attendre 2 secondes avant de réinitialiser
                }
              })
              .catch(err => {
                console.error('Erreur polling export:', err);
                this.exportMessage = 'Erreur communication serveur.';
                this.exportLoading = false;
                clearInterval(this.exportInterval);
              });
          }, 1000); // Vérifier la progression toutes les secondes
        })
        .catch(err => {
          console.error('Erreur démarrage export:', err);
          this.exportMessage = 'Erreur démarrage export.';
          this.exportLoading = false;
        });
    },

    downloadFile() {
      const a = document.createElement('a');
      a.href = `/batch-export/download/${this.formID}`;
      a.download = `${this.formID}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
    },
    async searchWebformValues(arg = 1) {
      this.loading = true;
      page = typeof arg === 'number' ? arg : 1;
      this.currentPage = page;

      const params = new URLSearchParams({ q: this.filters.search, page: page, limit: Alpine.store('limit') });

      try {
        const response = await fetch(drupalSettings.vactoryDashboard.searchPath, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(
            { keys: this.dynamiColumns },
          ),
        });

        const data = await response.json();
        this.webforms = data.data;
        this.totalPages = data.pages;
        this.pages = this.generatePageNumbers(data.page, data.pages);
        this.loading = false;
      } catch (error) {
        console.error('Error searching webforms:', error);
        this.loading = false;
      } finally {
        this.loading = false;
      }
    },
    formatData(value) {
      if (value && typeof value === 'object' && value.value && value.value.url && value.value.filename) {
        return `<a href="${value.value.url}" target="_blank" class="text-blue-600 underline">${value.value.filename}</a>`;
      } else if (typeof value === 'string' || typeof value === 'number') {
        return value !== null && value !== undefined && value !== '' ? value : '-';
      } else {
        return '-';
      }
    },
    uncheck() {
      document.getElementById('form-check-toggle').checked = false;
    },
    init() {
      this.loadWebforms();
    },
  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('webformTable', webformTable);
});

function iconSelectData() {
  return {
    icons: [],
    isLoading: false,
    hasError: false,

    init() {
      // Load icons from API
      this.loadIcons();
    },

    async loadIcons() {
      this.isLoading = true;
      this.hasError = false;

      try {
        const response = await fetch('/admin/vactory-dashboard/api/icons');
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.error) {
          throw new Error(data.error);
        }
        
        this.icons = data.icons || [];
        console.log(`Loaded ${this.icons.length} icons`);
        
      } catch (error) {
        console.error('Error loading icons:', error);
        this.hasError = true;
        this.icons = [];
      } finally {
        this.isLoading = false;
      }
    }
  };
}

// Register the Alpine.js component
document.addEventListener('alpine:init', () => {
  Alpine.data('iconSelectData', iconSelectData);
});
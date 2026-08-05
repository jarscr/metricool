const ApiClient = {
    baseUrl: 'api.php',

    async get(route, params = {}) {
        const query = new URLSearchParams({ route });
        Object.entries(params).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') {
                return;
            }

            query.set(key, String(value));
        });
        const response = await fetch(`${this.baseUrl}?${query.toString()}`);
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.error?.message || 'Error en la API');
        }

        return payload;
    },
};

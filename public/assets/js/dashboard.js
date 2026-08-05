let analyticsChart = null;
let networkChart = null;

const networkLabels = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
};

const networkColors = {
    instagram: '#f0447a',
    facebook: '#2e86ff',
    tiktok: '#24ddd5',
};

document.addEventListener('DOMContentLoaded', () => {
    initDates();
    document.getElementById('applyFilters').addEventListener('click', loadDashboard);
    loadBrands();
});

function initDates() {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - 90);

    document.getElementById('endDate').value = formatDate(end);
    document.getElementById('startDate').value = formatDate(start);
}

async function loadBrands() {
    try {
        const { data } = await ApiClient.get('brands');
        const select = document.getElementById('brandSelect');
        select.innerHTML = '';

        if (!data.length) {
            showError('No hay marcas en la base de datos. Ejecuta: php console/sync.php');
            return;
        }

        data.forEach((brand) => {
            const option = document.createElement('option');
            option.value = brand.id;
            option.textContent = brand.name;
            select.appendChild(option);
        });

        if (data.length === 1) {
            select.disabled = true;
        }

        select.addEventListener('change', loadDashboard);
        await loadDashboard();
    } catch (error) {
        showError(error.message);
    }
}

async function loadDashboard() {
    hideError();

    const brandId = document.getElementById('brandSelect').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const network = document.getElementById('networkSelect').value;
    const params = { brand_id: brandId, start_date: startDate, end_date: endDate };

    try {
        const postsRequest = ApiClient.get('posts', { ...params, network: network || undefined, per_page: 10 });

        const [overview, analytics, posts, scheduled, syncStatus] = await Promise.all([
            ApiClient.get('dashboard/overview', params),
            ApiClient.get('analytics/daily', params),
            postsRequest,
            ApiClient.get('scheduled', { brand_id: brandId }),
            ApiClient.get('sync/status', { brand_id: brandId }),
        ]);

        renderSyncBanner(overview.data, syncStatus.data);
        renderStats(overview.data, analytics.data);
        renderAnalyticsChart(analytics.data, network);
        renderNetworkChart(overview.data.networks);
        renderPosts(posts.data, network);
        renderScheduled(scheduled.data);
    } catch (error) {
        showError(error.message);
    }
}

function renderSyncBanner(overview, syncStatus) {
    const banner = document.getElementById('syncInfo');
    const brandName = overview.brand?.name || 'Marca';
    const lastSync = syncStatus?.created_at
        ? new Date(syncStatus.created_at).toLocaleString('es-CR')
        : 'sin registros';

    banner.textContent = `${brandName} · Período ${overview.period.start} a ${overview.period.end} · Última sync: ${lastSync} (${syncStatus?.status || 'N/A'})`;
}

function renderStats(overview, analyticsRows = []) {
    const grid = document.getElementById('statsGrid');
    const impressionsGrid = document.getElementById('impressionsGrid');
    const impressionTotals = Object.fromEntries(Object.keys(networkLabels).map((network) => [network, 0]));

    analyticsRows.forEach((row) => {
        if (Object.hasOwn(impressionTotals, row.network)) {
            impressionTotals[row.network] += Number(row.impressions || 0);
        }
    });
    const cards = [
        { label: 'Total posts', value: overview.totals.posts, className: 'stat-card--total' },
        { label: 'Total likes', value: overview.totals.likes, className: 'stat-card--total' },
        { label: 'Programados', value: overview.totals.scheduled_pending, className: 'stat-card--scheduled' },
    ];

    Object.entries(overview.networks).forEach(([network, stats]) => {
        if (stats.type === 'ads') {
            cards.push({
                label: `${networkLabels[network] || network} · impresiones`,
                value: stats.impressions_total,
                className: `stat-card--${network}`,
            });
            if (stats.ad_spend_total > 0) {
                cards.push({
                    label: `${networkLabels[network] || network} · inversión`,
                    value: stats.ad_spend_total,
                    className: `stat-card--${network}`,
                    isCurrency: true,
                });
            }
            if (stats.clicks_total > 0) {
                cards.push({
                    label: `${networkLabels[network] || network} · clics`,
                    value: stats.clicks_total,
                    className: `stat-card--${network}`,
                });
            }
            return;
        }

        if (stats.type === 'analytics') {
            cards.push({
                label: `${networkLabels[network] || network} · impresiones`,
                value: stats.impressions_total,
                className: `stat-card--${network}`,
            });
            if (stats.followers_latest > 0) {
                cards.push({
                    label: `${networkLabels[network] || network} · seguidores`,
                    value: stats.followers_latest,
                    className: `stat-card--${network}`,
                });
            }
            return;
        }

        cards.push({
            label: `${networkLabels[network] || network} posts`,
            value: stats.posts_count,
            className: `stat-card--${network}`,
        });
    });

    grid.innerHTML = cards.map((card) => `
        <article class="stat-card ${card.className}">
            <div class="stat-card__label">${card.label}</div>
            <div class="stat-card__value">${card.isCurrency ? formatCurrency(card.value) : formatNumber(card.value)}</div>
            <div class="stat-card__rule" aria-hidden="true"></div>
        </article>
    `).join('');

    impressionsGrid.innerHTML = Object.keys(networkLabels).map((network) => `
        <article class="stat-card stat-card--${network}">
            <div class="stat-card__label"><span class="network-dot network-dot--${network}"></span>${networkLabels[network]}</div>
            <div class="stat-card__value">${formatNumber(impressionTotals[network])}</div>
            <div class="stat-card__caption">impresiones acumuladas</div>
        </article>
    `).join('');
}

function renderAnalyticsChart(rows, networkFilter) {
    const isComparison = !networkFilter;
    const labels = [...new Set(rows.map((row) => row.date))].sort();
    const visibleNetworks = networkFilter
        ? [networkFilter]
        : Object.keys(networkLabels).filter((network) => rows.some((row) => row.network === network));
    const useDualAxes = isComparison
        && visibleNetworks.includes('instagram')
        && visibleNetworks.some((network) => network !== 'instagram');

    const datasets = visibleNetworks.map((network) => {
        const valuesByDate = {};
        rows
            .filter((row) => row.network === network)
            .forEach((row) => {
                valuesByDate[row.date] = Number(row.impressions || 0);
            });

        const color = networkColors[network] || '#d7ff64';
        return {
            label: networkLabels[network] || network,
            data: labels.map((label) => valuesByDate[label] ?? null),
            borderColor: color,
            backgroundColor: color,
            fill: false,
            tension: 0.3,
            spanGaps: true,
            borderWidth: 2,
            pointRadius: 2,
            pointHoverRadius: 4,
            yAxisID: useDualAxes && network === 'instagram' ? 'yInstagram' : 'yVolume',
        };
    });

    const scaleHint = document.getElementById('analyticsScaleHint');
    scaleHint.textContent = useDualAxes
        ? 'Escalas separadas: Instagram en el eje izquierdo; Facebook y TikTok en el eje derecho.'
        : '';

    const yScales = useDualAxes
        ? {
            yInstagram: {
                type: 'linear',
                position: 'left',
                beginAtZero: true,
                title: { display: true, text: 'Instagram', color: networkColors.instagram },
                ticks: { color: networkColors.instagram },
                grid: { color: 'rgba(133, 142, 155, 0.12)' },
            },
            yVolume: {
                type: 'linear',
                position: 'right',
                beginAtZero: true,
                title: { display: true, text: 'Facebook / TikTok', color: '#858e9b' },
                ticks: { color: '#858e9b' },
                grid: { drawOnChartArea: false },
            },
        }
        : {
            yVolume: {
                type: 'linear',
                position: 'left',
                beginAtZero: true,
                ticks: { color: '#858e9b' },
                grid: { color: 'rgba(133, 142, 155, 0.12)' },
            },
        };

    if (analyticsChart) {
        analyticsChart.destroy();
    }

    analyticsChart = new Chart(document.getElementById('analyticsChart'), {
        type: 'line',
        data: {
            labels,
            datasets,
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: {
                        color: '#c5cad2',
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 7,
                        boxHeight: 7,
                        padding: 18,
                        font: { size: 11 },
                    },
                },
                tooltip: {
                    callbacks: {
                        label: (context) => `${context.dataset.label}: ${formatNumber(context.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: {
                    ticks: { color: '#858e9b', maxRotation: 45 },
                    grid: { display: false },
                    border: { color: '#343c47' },
                },
                ...yScales,
            },
        },
    });
}

function renderNetworkChart(networks) {
    const postNetworks = Object.entries(networks).filter(([, stats]) => stats.type === 'posts' || !stats.type);
    const labels = postNetworks.map(([key]) => networkLabels[key] || key);
    const values = postNetworks.map(([, item]) => item.posts_count);
    const colors = postNetworks.map(([key]) => networkColors[key] || '#d7ff64');

    if (networkChart) {
        networkChart.destroy();
    }

    networkChart = new Chart(document.getElementById('networkChart'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 0,
                hoverOffset: 5,
            }],
        },
        options: {
            responsive: true,
            cutout: '66%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#c5cad2',
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 7,
                        boxHeight: 7,
                        padding: 18,
                        font: { size: 11 },
                    },
                },
            },
        },
    });
}

function renderPosts(posts, network) {
    const container = document.getElementById('postsList');

    if (!posts.length) {
        container.innerHTML = '<div class="empty-state">No hay publicaciones en el período seleccionado.</div>';
        return;
    }

    container.innerHTML = posts.map((post) => `
        <article class="post-card">
            <div class="post-card__content">
                <span class="network-badge network-badge--${post.network}">${networkLabels[post.network]}</span>
                <p>${escapeHtml(truncate(post.text || 'Sin contenido', 220))}</p>
            </div>
            <div class="post-card__meta">
                <span><small>Me gusta</small><strong>${formatNumber(post.likes)}</strong></span>
                <span><small>Comentarios</small><strong>${formatNumber(post.comments)}</strong></span>
                <span><small>Impresiones</small><strong>${formatNumber(post.impressions)}</strong></span>
                <time datetime="${escapeHtml(post.published_date || '')}">${formatDateTime(post.published_date)}</time>
            </div>
        </article>
    `).join('');
}

function renderScheduled(items) {
    const container = document.getElementById('scheduledList');

    if (!items.length) {
        container.innerHTML = '<div class="empty-state">No hay posts programados pendientes.</div>';
        return;
    }

    container.innerHTML = items.map((item) => `
        <article class="post-card">
            <div class="post-card__content">
                <span class="network-badge network-badge--${item.network}">${networkLabels[item.network] || item.network}</span>
                <p>${escapeHtml(truncate(item.content || 'Sin contenido', 220))}</p>
            </div>
            <div class="post-card__meta">
                <time datetime="${escapeHtml(item.scheduled_date || '')}">${formatDateTime(item.scheduled_date)}</time>
                <span class="status-label">${escapeHtml(item.status || 'Pendiente')}</span>
            </div>
        </article>
    `).join('');
}

function showError(message) {
    const box = document.getElementById('errorBox');
    box.textContent = message;
    box.classList.remove('hidden');
}

function hideError() {
    document.getElementById('errorBox').classList.add('hidden');
}

function formatDate(date) {
    return date.toISOString().slice(0, 10);
}

function formatDateTime(value) {
    if (!value) return '-';
    return new Date(value).toLocaleString('es-CR');
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-CR').format(value || 0);
}

function formatCurrency(value) {
    return new Intl.NumberFormat('es-CR', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(value || 0);
}

function truncate(text, max) {
    return text.length > max ? `${text.slice(0, max)}...` : text;
}

function escapeHtml(text) {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

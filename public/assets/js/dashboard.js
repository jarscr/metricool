let analyticsChart = null;
let followersChart = null;

const networkLabels = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    facebookads: 'Meta Ads',
    googleads: 'Google Ads',
};

const organicNetworks = ['instagram', 'facebook', 'tiktok'];
const adsNetworks = ['facebookads', 'googleads'];

const networkColors = {
    instagram: '#f0447a',
    facebook: '#2e86ff',
    tiktok: '#24ddd5',
    facebookads: '#5b8def',
    googleads: '#f9ab00',
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

        select.addEventListener('change', () => {
            document.getElementById('networkSelect').value = '';
            loadDashboard();
        });
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
    const params = { brand_id: brandId, start_date: startDate, end_date: endDate };

    try {
        const overview = await ApiClient.get('dashboard/overview', params);
        const network = updateNetworkFilter(overview.data.networks);
        const [analytics, posts, scheduled, syncStatus] = await Promise.all([
            ApiClient.get('analytics/daily', params),
            ApiClient.get('posts', { ...params, network: network || undefined, per_page: 10 }),
            ApiClient.get('scheduled', { brand_id: brandId }),
            ApiClient.get('sync/status', { brand_id: brandId }),
        ]);

        renderSyncBanner(overview.data, syncStatus.data);
        renderStats(overview.data, analytics.data);
        renderAnalyticsChart(analytics.data, network);
        renderFollowersChart(analytics.data, network);
        renderPosts(posts.data, network);
        renderScheduled(scheduled.data);
    } catch (error) {
        showError(error.message);
    }
}

function updateNetworkFilter(networks) {
    const select = document.getElementById('networkSelect');
    const previousValue = select.value;
    const availableNetworks = organicNetworks.filter((network) => networks[network]?.type === 'posts');

    select.innerHTML = [
        '<option value="">Todas</option>',
        ...availableNetworks.map((network) => `<option value="${network}">${networkLabels[network]}</option>`),
    ].join('');

    select.value = availableNetworks.includes(previousValue) ? previousValue : '';
    select.disabled = availableNetworks.length <= 1;

    return select.value;
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
    const followersGrid = document.getElementById('followersGrid');
    const adsGrid = document.getElementById('adsGrid');
    const activeOrganicNetworks = organicNetworks.filter(
        (network) => overview.networks[network]?.type === 'posts'
    );
    const impressionTotals = Object.fromEntries(activeOrganicNetworks.map((network) => [network, 0]));

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

    activeOrganicNetworks.forEach((network) => {
        const stats = overview.networks[network];
        cards.push({
            label: `${networkLabels[network]} posts`,
            value: stats.posts_count,
            className: `stat-card--${network}`,
        });
    });

    grid.innerHTML = cards.map((card) => `
        <article class="stat-card ${card.className}">
            <div class="stat-card__label">${card.label}</div>
            <div class="stat-card__value">${formatNumber(card.value)}</div>
            <div class="stat-card__rule" aria-hidden="true"></div>
        </article>
    `).join('');

    renderTrendSummary({
        grid: impressionsGrid,
        totalElement: document.getElementById('impressionsTotal'),
        compositionElement: document.getElementById('impressionsComposition'),
        networks: activeOrganicNetworks,
        values: impressionTotals,
        rows: analyticsRows,
        metric: 'impressions',
    });
    toggleSection('reachSection', activeOrganicNetworks.length > 0);

    const followerNetworks = activeOrganicNetworks.filter(
        (network) => Number(overview.networks[network].followers_latest || 0) > 0
    );
    const followerTotals = Object.fromEntries(followerNetworks.map(
        (network) => [network, Number(overview.networks[network].followers_latest || 0)]
    ));
    renderTrendSummary({
        grid: followersGrid,
        totalElement: document.getElementById('followersTotal'),
        compositionElement: document.getElementById('followersComposition'),
        networks: followerNetworks,
        values: followerTotals,
        rows: analyticsRows,
        metric: 'followers',
        ignoreZero: true,
    });
    toggleSection('followersSection', followerNetworks.length > 0);

    const availableAdsNetworks = adsNetworks.filter((network) => overview.networks[network]?.type === 'ads');
    adsGrid.innerHTML = availableAdsNetworks.map((network) => {
        const stats = overview.networks[network];
        return `
            <article class="ads-card ads-card--${network}">
                <div class="ads-card__header">
                    <span class="network-dot network-dot--${network}"></span>
                    <strong>${networkLabels[network]}</strong>
                    <span>Datos del período</span>
                </div>
                <dl class="ads-card__metrics">
                    <div><dt>Impresiones</dt><dd>${formatNumber(stats.impressions_total)}</dd></div>
                    <div><dt>Clics</dt><dd>${formatNumber(stats.clicks_total)}</dd></div>
                    <div><dt>Alcance</dt><dd>${formatNumber(stats.reach_total)}</dd></div>
                </dl>
            </article>
        `;
    }).join('');
    toggleSection('adsSection', availableAdsNetworks.length > 0);
}

function renderTrendSummary({
    grid,
    totalElement,
    compositionElement,
    networks,
    values,
    rows,
    metric,
    ignoreZero = false,
}) {
    const total = networks.reduce((sum, network) => sum + Number(values[network] || 0), 0);
    totalElement.textContent = formatCompactNumber(total);

    compositionElement.innerHTML = networks.map((network) => {
        const share = total > 0 ? (Number(values[network] || 0) / total) * 100 : 0;
        return `<span class="composition-bar__segment composition-bar__segment--${network}" style="width: ${share.toFixed(2)}%"></span>`;
    }).join('');

    grid.innerHTML = networks.map((network) => {
        const trend = getMetricTrend(rows, network, metric, ignoreZero);
        return `
            <article class="trend-network-card trend-network-card--${network}">
                <strong>${formatCompactNumber(values[network])}<span class="trend-marker" title="${trend.label}">${trend.symbol}</span></strong>
                <span><i class="network-dot network-dot--${network}" aria-hidden="true"></i>${networkLabels[network]}</span>
            </article>
        `;
    }).join('');
}

function getMetricTrend(rows, network, metric, ignoreZero = false) {
    const values = rows
        .filter((row) => row.network === network)
        .sort((a, b) => String(a.date).localeCompare(String(b.date)))
        .map((row) => Number(row[metric] || 0))
        .filter((value) => !ignoreZero || value > 0);

    if (values.length < 2) {
        return { symbol: '—', label: 'Sin historial suficiente' };
    }

    const change = values.at(-1) - values[0];
    if (change > 0) return { symbol: '↑', label: 'Tendencia ascendente' };
    if (change < 0) return { symbol: '↓', label: 'Tendencia descendente' };
    return { symbol: '→', label: 'Sin variación' };
}

function toggleSection(id, visible) {
    document.getElementById(id).classList.toggle('hidden', !visible);
}

function renderAnalyticsChart(rows, networkFilter) {
    if (analyticsChart) {
        analyticsChart.destroy();
    }
    analyticsChart = renderTrendChart({
        canvasId: 'analyticsChart',
        rows,
        metric: 'impressions',
        networkFilter,
        hintId: 'analyticsScaleHint',
        ignoreZero: false,
    });
}

function renderFollowersChart(rows, networkFilter) {
    if (followersChart) {
        followersChart.destroy();
    }
    followersChart = renderTrendChart({
        canvasId: 'followersChart',
        rows,
        metric: 'followers',
        networkFilter,
        hintId: 'followersScaleHint',
        ignoreZero: true,
    });
}

function renderTrendChart({ canvasId, rows, metric, networkFilter, hintId, ignoreZero }) {
    const chartRows = rows.filter((row) => organicNetworks.includes(row.network));
    const labels = [...new Set(chartRows.map((row) => row.date))].sort();
    const visibleNetworks = networkFilter
        ? [networkFilter].filter((network) => chartRows.some((row) => row.network === network && (!ignoreZero || Number(row[metric] || 0) > 0)))
        : organicNetworks.filter((network) => chartRows.some((row) => row.network === network && (!ignoreZero || Number(row[metric] || 0) > 0)));
    const axisSplit = getScaleSplit(chartRows, visibleNetworks, metric, ignoreZero);
    const canvas = document.getElementById(canvasId);
    const emptyState = document.getElementById(`${canvasId}Empty`);
    const hasChartData = visibleNetworks.length > 0 && labels.length > 0;
    canvas.classList.toggle('hidden', !hasChartData);
    emptyState.classList.toggle('hidden', hasChartData);
    if (!hasChartData) {
        document.getElementById(hintId).textContent = '';
        return null;
    }

    const datasets = visibleNetworks.map((network, index) => {
        const valuesByDate = {};
        chartRows
            .filter((row) => row.network === network)
            .forEach((row) => {
                const value = Number(row[metric] || 0);
                valuesByDate[row.date] = ignoreZero && value <= 0 ? null : value;
            });

        const color = networkColors[network] || '#ca3457';
        return {
            label: networkLabels[network] || network,
            data: labels.map((label) => valuesByDate[label] ?? null),
            borderColor: color,
            backgroundColor: color,
            borderDash: index === 1 ? [7, 4] : (index === 2 ? [2, 4] : []),
            fill: false,
            tension: 0.28,
            spanGaps: true,
            borderWidth: 2,
            pointRadius: labels.length > 45 ? 0 : 2.5,
            pointHoverRadius: 5,
            pointHitRadius: 12,
            yAxisID: axisSplit === network ? 'ySecondary' : 'yPrimary',
        };
    });

    const hint = document.getElementById(hintId);
    hint.textContent = axisSplit
        ? `${networkLabels[axisSplit]} usa una escala independiente para mantener visible su tendencia.`
        : '';

    const scales = {
        x: {
            ticks: {
                color: '#4b4b4b',
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 12,
                callback(value, index) {
                    return formatChartDate(labels[index]);
                },
            },
            grid: { display: false },
            border: { color: '#c9c9c9' },
        },
        yPrimary: chartScale('left', true),
    };
    if (axisSplit) {
        scales.ySecondary = chartScale('right', false, networkColors[axisSplit]);
    }

    return new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? false : { duration: 280 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => `${context.dataset.label}: ${formatNumber(context.parsed.y)}`,
                    },
                },
            },
            scales,
        },
    });
}

function getScaleSplit(rows, networks, metric, ignoreZero) {
    if (networks.length < 2) return null;

    const maxima = networks.map((network) => {
        const values = rows
            .filter((row) => row.network === network)
            .map((row) => Number(row[metric] || 0))
            .filter((value) => !ignoreZero || value > 0);
        return { network, maximum: Math.max(0, ...values) };
    }).filter((item) => item.maximum > 0).sort((a, b) => a.maximum - b.maximum);

    if (maxima.length < 2) return null;
    return maxima.at(-1).maximum >= maxima[0].maximum * 8 ? maxima[0].network : null;
}

function chartScale(position, drawGrid, color = '#4b4b4b') {
    return {
        type: 'linear',
        position,
        beginAtZero: true,
        ticks: {
            color,
            maxTicksLimit: 5,
            callback: (value) => formatCompactNumber(value),
        },
        grid: drawGrid ? { color: 'rgba(17, 17, 17, 0.1)' } : { drawOnChartArea: false },
        border: { display: false },
    };
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

function formatCompactNumber(value) {
    return new Intl.NumberFormat('es-CR', {
        notation: 'compact',
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
}

function formatChartDate(value) {
    if (!value) return '';
    const date = new Date(`${value}T00:00:00`);
    return date.toLocaleDateString('es-CR', { day: 'numeric', month: 'short' }).replace('.', '');
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

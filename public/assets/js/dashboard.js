let analyticsChart = null;
let followersChart = null;
let currentReportData = null;

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
    document.getElementById('downloadReport').addEventListener('click', downloadDashboardReport);
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

    const reportButton = document.getElementById('downloadReport');
    currentReportData = null;
    reportButton.disabled = true;
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
        currentReportData = {
            overview: overview.data,
            analytics: analytics.data,
            network,
            posts: posts.data,
            scheduled: scheduled.data,
            syncStatus: syncStatus.data,
        };
        reportButton.disabled = false;
    } catch (error) {
        showError(error.message);
    }
}

async function downloadDashboardReport() {
    if (!currentReportData) {
        showError('Primero actualiza el dashboard para generar el reporte.');
        return;
    }

    const button = document.getElementById('downloadReport');
    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Generando...';
    hideError();

    try {
        await document.fonts?.ready;
        const canvases = buildReportCanvases(currentReportData);
        const images = await Promise.all(canvases.map(async (canvas) => {
            const jpegBlob = await canvasToBlob(canvas, 'image/jpeg', 0.94);
            return {
                bytes: new Uint8Array(await jpegBlob.arrayBuffer()),
                width: canvas.width,
                height: canvas.height,
            };
        }));
        const pdfBlob = createPdfFromJpegs(images);
        triggerDownload(pdfBlob, buildReportFilename(currentReportData.overview));
    } catch (error) {
        showError(`No fue posible generar el PDF: ${error.message}`);
    } finally {
        button.disabled = false;
        button.textContent = originalLabel;
    }
}

function buildReportCanvases(reportData) {
    const pages = [buildSummaryReportPage(reportData), buildDetailsReportPage(reportData)];
    pages.push(...buildListReportPages(reportData));
    pages.forEach((canvas, index) => drawReportFooter(canvas.getContext('2d'), index + 1, pages.length));
    return pages;
}

function buildSummaryReportPage({ overview, analytics, network, syncStatus }) {
    const canvas = createReportPage(overview, 'Resumen del dashboard');
    const ctx = canvas.getContext('2d');
    const selectedNetworks = network
        ? [network]
        : organicNetworks.filter((item) => overview.networks[item]?.type === 'posts');
    const impressionTotal = analytics
        .filter((row) => selectedNetworks.includes(row.network))
        .reduce((total, row) => total + Number(row.impressions || 0), 0);
    const followerTotal = selectedNetworks.reduce(
        (total, item) => total + Number(overview.networks[item]?.followers_latest || 0),
        0
    );

    const lastSync = syncStatus?.created_at
        ? new Date(syncStatus.created_at).toLocaleString('es-CR')
        : 'sin registros';
    ctx.fillStyle = '#6b5960';
    ctx.font = '400 18px Roboto, Arial, sans-serif';
    ctx.fillText(`Última sincronización: ${lastSync} (${syncStatus?.status || 'N/A'})`, 76, 166);

    drawReportMetric(ctx, 76, 190, 350, 'Publicaciones', overview.totals.posts);
    drawReportMetric(ctx, 460, 190, 350, 'Me gusta', overview.totals.likes);
    drawReportMetric(ctx, 844, 190, 350, 'Impresiones', impressionTotal);
    drawReportMetric(ctx, 1228, 190, 380, 'Seguidores', followerTotal);

    const charts = [
        { canvas: document.getElementById('followersChart'), title: 'Evolución de seguidores' },
        { canvas: document.getElementById('analyticsChart'), title: 'Evolución de impresiones' },
    ].filter((item) => item.canvas && !item.canvas.classList.contains('hidden'));

    const chartHeight = charts.length > 1 ? 340 : 700;
    charts.forEach((item, index) => {
        const y = 352 + index * 380;
        drawReportChart(ctx, item.canvas, item.title, 76, y, 1532, chartHeight);
    });

    if (!charts.length) {
        ctx.fillStyle = '#f7f2f4';
        ctx.fillRect(76, 352, 1532, 670);
        ctx.fillStyle = '#6b5960';
        ctx.font = '500 27px Roboto, Arial, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No hay datos para graficar en el período seleccionado.', 842, 690);
        ctx.textAlign = 'left';
    }
    return canvas;
}

function buildDetailsReportPage({ overview }) {
    const canvas = createReportPage(overview, 'Actividad y publicidad');
    const ctx = canvas.getContext('2d');
    const organic = organicNetworks.filter((network) => overview.networks[network]?.type === 'posts');
    const activity = [
        ['Total posts', overview.totals.posts],
        ['Total likes', overview.totals.likes],
        ['Programados', overview.totals.scheduled_pending],
        ...organic.map((network) => [`${networkLabels[network]} posts`, overview.networks[network].posts_count]),
    ];

    drawReportSectionTitle(ctx, 'Panorama general', 176);
    activity.forEach(([label, value], index) => {
        const column = index % 3;
        const row = Math.floor(index / 3);
        drawReportMetric(ctx, 76 + column * 520, 210 + row * 145, 488, label, value);
    });

    const activityRows = Math.max(1, Math.ceil(activity.length / 3));
    let y = 230 + activityRows * 145;
    drawReportSectionTitle(ctx, 'Rendimiento de anuncios', y);
    y += 34;
    const ads = adsNetworks.filter((network) => overview.networks[network]?.type === 'ads');
    if (!ads.length) {
        drawReportEmpty(ctx, 'No hay datos publicitarios en el período seleccionado.', y, 210);
        return canvas;
    }

    ads.forEach((network, index) => {
        const stats = overview.networks[network];
        const x = 76 + index * 778;
        drawAdsReportCard(ctx, x, y, 746, networkLabels[network], stats);
    });
    return canvas;
}

function buildListReportPages({ overview, posts, scheduled }) {
    return [
        ...paginateReportList(overview, 'Publicaciones recientes', posts, 'No hay publicaciones en el período seleccionado.', false),
        ...paginateReportList(overview, 'Posts programados', scheduled, 'No hay posts programados pendientes.', true),
    ];
}

function paginateReportList(overview, title, items, emptyMessage, scheduled) {
    const perPage = 7;
    const chunks = items.length
        ? Array.from({ length: Math.ceil(items.length / perPage) }, (_, index) => items.slice(index * perPage, (index + 1) * perPage))
        : [[]];

    return chunks.map((chunk, pageIndex) => {
        const suffix = chunks.length > 1 ? ` (${pageIndex + 1}/${chunks.length})` : '';
        const canvas = createReportPage(overview, `${title}${suffix}`);
        const ctx = canvas.getContext('2d');
        if (!chunk.length) {
            drawReportEmpty(ctx, emptyMessage, 190, 770);
            return canvas;
        }

        chunk.forEach((item, index) => drawReportListItem(ctx, item, 76, 176 + index * 132, scheduled));
        return canvas;
    });
}

function createReportPage(overview, sectionTitle) {
    const canvas = document.createElement('canvas');
    canvas.width = 1684;
    canvas.height = 1190;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#6c002e';
    ctx.fillRect(0, 0, canvas.width, 132);
    ctx.fillStyle = '#ffffff';
    ctx.font = '700 44px Roboto, Arial, sans-serif';
    ctx.fillText(sectionTitle, 76, 62);
    ctx.font = '400 23px Roboto, Arial, sans-serif';
    ctx.fillText(overview.brand?.name || 'Marca', 76, 101);
    ctx.textAlign = 'right';
    ctx.fillText(`${formatReportDate(overview.period.start)} - ${formatReportDate(overview.period.end)}`, 1608, 82);
    ctx.textAlign = 'left';
    return canvas;
}

function drawReportFooter(ctx, page, totalPages) {
    ctx.fillStyle = '#6b5960';
    ctx.font = '400 18px Roboto, Arial, sans-serif';
    ctx.fillText(`Generado el ${new Date().toLocaleString('es-CR')}`, 76, 1147);
    ctx.textAlign = 'right';
    ctx.fillText(`Panel de rendimiento by IDEAS MCW · Página ${page} de ${totalPages}`, 1608, 1147);
    ctx.textAlign = 'left';
}

function drawReportSectionTitle(ctx, title, y) {
    ctx.fillStyle = '#2d151c';
    ctx.font = '700 28px Roboto, Arial, sans-serif';
    ctx.fillText(title, 76, y);
}

function drawAdsReportCard(ctx, x, y, width, label, stats) {
    ctx.fillStyle = '#f7f2f4';
    ctx.fillRect(x, y, width, 210);
    ctx.fillStyle = '#2d151c';
    ctx.font = '700 26px Roboto, Arial, sans-serif';
    ctx.fillText(label, x + 28, y + 45);
    const metrics = [
        ['Impresiones', stats.impressions_total],
        ['Clics', stats.clicks_total],
        ['Alcance', stats.reach_total],
    ];
    metrics.forEach(([metric, value], index) => {
        const metricX = x + 28 + index * 230;
        ctx.fillStyle = '#6b5960';
        ctx.font = '500 18px Roboto, Arial, sans-serif';
        ctx.fillText(metric, metricX, y + 99);
        ctx.fillStyle = '#2d151c';
        ctx.font = '700 34px Roboto, Arial, sans-serif';
        ctx.fillText(formatNumber(value), metricX, y + 151);
    });
}

function drawReportListItem(ctx, item, x, y, scheduled) {
    ctx.fillStyle = '#f7f2f4';
    ctx.fillRect(x, y, 1532, 112);
    const network = networkLabels[item.network] || item.network || 'Red';
    ctx.fillStyle = '#6c002e';
    ctx.font = '700 18px Roboto, Arial, sans-serif';
    ctx.fillText(network, x + 22, y + 30);
    const text = scheduled ? (item.content || 'Sin contenido') : (item.text || 'Sin contenido');
    ctx.fillStyle = '#2d151c';
    ctx.font = '400 19px Roboto, Arial, sans-serif';
    drawWrappedText(ctx, text, x + 22, y + 61, 940, 25, 2);
    ctx.textAlign = 'right';
    ctx.fillStyle = '#6b5960';
    ctx.font = '400 17px Roboto, Arial, sans-serif';
    if (scheduled) {
        ctx.fillText(formatDateTime(item.scheduled_date), x + 1508, y + 42);
        ctx.fillText(item.status || 'Pendiente', x + 1508, y + 76);
    } else {
        ctx.fillText(formatDateTime(item.published_date), x + 1508, y + 31);
        ctx.fillText(`Me gusta ${formatNumber(item.likes)} · Comentarios ${formatNumber(item.comments)} · Impresiones ${formatNumber(item.impressions)}`, x + 1508, y + 69);
    }
    ctx.textAlign = 'left';
}

function drawWrappedText(ctx, text, x, y, maxWidth, lineHeight, maxLines) {
    const words = String(text).replace(/\s+/g, ' ').trim().split(' ');
    let line = '';
    let lineIndex = 0;
    for (const word of words) {
        const candidate = line ? `${line} ${word}` : word;
        if (ctx.measureText(candidate).width <= maxWidth) {
            line = candidate;
            continue;
        }
        ctx.fillText(line, x, y + lineIndex * lineHeight);
        lineIndex += 1;
        line = word;
        if (lineIndex >= maxLines) return;
    }
    if (lineIndex < maxLines && line) ctx.fillText(line, x, y + lineIndex * lineHeight);
}

function drawReportEmpty(ctx, message, y, height) {
    ctx.fillStyle = '#f7f2f4';
    ctx.fillRect(76, y, 1532, height);
    ctx.fillStyle = '#6b5960';
    ctx.font = '500 25px Roboto, Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(message, 842, y + height / 2);
    ctx.textAlign = 'left';
}

function drawReportMetric(ctx, x, y, width, label, value) {
    ctx.fillStyle = '#f7f2f4';
    ctx.fillRect(x, y, width, 122);
    ctx.fillStyle = '#6b5960';
    ctx.font = '500 20px Roboto, Arial, sans-serif';
    ctx.fillText(label, x + 24, y + 39);
    ctx.fillStyle = '#2d151c';
    ctx.font = '700 39px Roboto, Arial, sans-serif';
    ctx.fillText(formatNumber(value), x + 24, y + 91);
}

function drawReportChart(ctx, sourceCanvas, title, x, y, width, height) {
    ctx.fillStyle = '#2d151c';
    ctx.font = '700 25px Roboto, Arial, sans-serif';
    ctx.fillText(title, x, y + 31);
    ctx.strokeStyle = '#e1d5d9';
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y + 52, width, height - 52);
    ctx.drawImage(sourceCanvas, x + 18, y + 70, width - 36, height - 88);
}

function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) resolve(blob);
            else reject(new Error('El navegador no pudo preparar la imagen del reporte.'));
        }, type, quality);
    });
}

function createPdfFromJpeg(jpegBytes, imageWidth, imageHeight) {
    return createPdfFromJpegs([{ bytes: jpegBytes, width: imageWidth, height: imageHeight }]);
}

function createPdfFromJpegs(images) {
    const encoder = new TextEncoder();
    const pageWidth = 842;
    const pageHeight = 595;
    const content = `q\n${pageWidth} 0 0 ${pageHeight} 0 0 cm\n/ReportImage Do\nQ`;
    const pageObjectNumbers = images.map((_, index) => 3 + index * 3);
    const objects = [
        encoder.encode('<< /Type /Catalog /Pages 2 0 R >>'),
        encoder.encode(`<< /Type /Pages /Kids [${pageObjectNumbers.map((number) => `${number} 0 R`).join(' ')}] /Count ${images.length} >>`),
    ];
    images.forEach((image, index) => {
        const pageObject = pageObjectNumbers[index];
        const imageObject = pageObject + 1;
        const contentObject = pageObject + 2;
        objects.push(
            encoder.encode(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /XObject << /ReportImage ${imageObject} 0 R >> >> /Contents ${contentObject} 0 R >>`),
            joinByteArrays([
                encoder.encode(`<< /Type /XObject /Subtype /Image /Width ${image.width} /Height ${image.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${image.bytes.length} >>\nstream\n`),
                image.bytes,
                encoder.encode('\nendstream'),
            ]),
            encoder.encode(`<< /Length ${encoder.encode(content).length} >>\nstream\n${content}\nendstream`)
        );
    });
    const chunks = [encoder.encode('%PDF-1.4\n%PDFREPORT\n')];
    const offsets = [0];
    let length = chunks[0].length;

    objects.forEach((object, index) => {
        offsets.push(length);
        const header = encoder.encode(`${index + 1} 0 obj\n`);
        const footer = encoder.encode('\nendobj\n');
        chunks.push(header, object, footer);
        length += header.length + object.length + footer.length;
    });

    const xrefOffset = length;
    const xref = [
        'xref',
        `0 ${objects.length + 1}`,
        '0000000000 65535 f ',
        ...offsets.slice(1).map((offset) => `${String(offset).padStart(10, '0')} 00000 n `),
        `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>`,
        `startxref\n${xrefOffset}`,
        '%%EOF',
    ].join('\n');
    chunks.push(encoder.encode(`${xref}\n`));
    return new Blob(chunks, { type: 'application/pdf' });
}

function joinByteArrays(chunks) {
    const totalLength = chunks.reduce((total, chunk) => total + chunk.length, 0);
    const result = new Uint8Array(totalLength);
    let offset = 0;
    chunks.forEach((chunk) => {
        result.set(chunk, offset);
        offset += chunk.length;
    });
    return result;
}

function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function buildReportFilename(overview) {
    const brand = String(overview.brand?.name || 'marca')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
    return `reporte-${brand || 'marca'}-${overview.period.start}-${overview.period.end}.pdf`;
}

function formatReportDate(value) {
    if (!value) return '';
    return new Date(`${value}T00:00:00`).toLocaleDateString('es-CR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
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

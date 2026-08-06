const fs = require('node:fs');
const vm = require('node:vm');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function element() {
    const classes = new Set(['hidden']);
    return {
        innerHTML: '',
        value: '',
        disabled: false,
        classList: {
            toggle(name, force) {
                if (force) classes.add(name);
                else classes.delete(name);
            },
            contains(name) {
                return classes.has(name);
            },
        },
    };
}

const elements = Object.fromEntries([
    'statsGrid',
    'impressionsGrid',
    'followersGrid',
    'adsGrid',
    'reachSection',
    'followersSection',
    'adsSection',
    'networkSelect',
    'impressionsTotal',
    'followersTotal',
    'impressionsComposition',
    'followersComposition',
].map((id) => [id, element()]));

const context = vm.createContext({
    console,
    Intl,
    Date,
    ApiClient: {},
    Chart: function Chart() {},
    document: {
        addEventListener() {},
        getElementById(id) {
            return elements[id] || element();
        },
    },
});

const code = fs.readFileSync('public/assets/js/dashboard.js', 'utf8');
vm.runInContext(code, context);

context.__overview = {
    totals: { posts: 8, likes: 90, scheduled_pending: 1 },
    networks: {
        facebook: { type: 'posts', posts_count: 8, followers_latest: 700 },
        facebookads: { type: 'ads', impressions_total: 12000, clicks_total: 350, reach_total: 8000 },
        googleads: { type: 'ads', impressions_total: 9000, clicks_total: 210, reach_total: 4500 },
    },
};
context.__analytics = [
    { network: 'facebook', date: '2026-08-01', impressions: 20, followers: 680 },
    { network: 'facebook', date: '2026-08-02', impressions: 25, followers: 700 },
    { network: 'facebookads', impressions: 12000 },
    { network: 'googleads', impressions: 9000 },
];

vm.runInContext('renderStats(__overview, __analytics)', context);

assert(elements.statsGrid.innerHTML.includes('Facebook posts'), 'Debe mostrar la red orgánica disponible');
assert(!elements.statsGrid.innerHTML.includes('Instagram posts'), 'No debe inventar Instagram ausente');
assert(!elements.statsGrid.innerHTML.includes('TikTok posts'), 'No debe inventar TikTok ausente');
assert(elements.followersGrid.innerHTML.includes('700'), 'Debe mostrar seguidores de Facebook');
assert(elements.followersGrid.innerHTML.includes('↑'), 'Debe mostrar la tendencia real de seguidores');
assert(elements.impressionsTotal.innerHTML === '' && elements.impressionsTotal.textContent === '45', 'Debe resumir las impresiones del período');
assert(elements.followersTotal.textContent === '700', 'Debe resumir los seguidores disponibles');
assert(elements.impressionsComposition.innerHTML.includes('composition-bar__segment--facebook'), 'Debe mostrar la composición por red');
assert(elements.adsGrid.innerHTML.includes('Meta Ads'), 'Debe mostrar Meta Ads cuando hay datos');
assert(elements.adsGrid.innerHTML.includes('Google Ads'), 'Debe mostrar Google Ads cuando hay datos');
assert(!/gasto|inversi[oó]n|spend/i.test(elements.adsGrid.innerHTML), 'No debe mostrar gasto publicitario');
assert(!elements.followersSection.classList.contains('hidden'), 'La sección de seguidores debe ser visible');
assert(!elements.adsSection.classList.contains('hidden'), 'La sección Ads debe ser visible');

vm.runInContext('updateNetworkFilter(__overview.networks)', context);
assert(elements.networkSelect.innerHTML.includes('Facebook'), 'El selector debe incluir Facebook');
assert(!elements.networkSelect.innerHTML.includes('Instagram'), 'El selector no debe incluir redes ausentes');
assert(!elements.networkSelect.innerHTML.includes('Meta Ads'), 'El selector de posts no debe incluir Ads');

context.__facebookOnly = {
    totals: { posts: 2, likes: 4, scheduled_pending: 0 },
    networks: {
        facebook: { type: 'posts', posts_count: 2, followers_latest: 0 },
    },
};
vm.runInContext('renderStats(__facebookOnly, [{ network: "facebook", impressions: 45 }])', context);
assert(elements.adsSection.classList.contains('hidden'), 'Debe ocultar Ads cuando la marca no tiene datos publicitarios');
assert(elements.followersSection.classList.contains('hidden'), 'Debe ocultar seguidores cuando Metricool no devuelve un total');
assert(!elements.impressionsGrid.innerHTML.includes('Instagram'), 'Una marca solo Facebook no debe reservar espacio para Instagram');

context.__scaleRows = [
    { network: 'instagram', impressions: 120 },
    { network: 'facebook', impressions: 90000 },
    { network: 'tiktok', impressions: 70000 },
];
assert(
    vm.runInContext('getScaleSplit(__scaleRows, ["instagram", "facebook", "tiktok"], "impressions", false)', context) === 'instagram',
    'Debe separar la escala de la red pequeña para que su línea continúe visible'
);

console.log('[ok] dashboard render condicional, seguidores y Ads sin gasto');

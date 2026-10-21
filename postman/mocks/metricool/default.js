// Synthetic values, response shapes from https://app.metricool.com/api/swagger.json.
const http = require('http');
http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    res.setHeader('Content-Type', 'application/json');
    if (req.method !== 'GET' || req.headers['x-mc-auth'] !== 'fixture-token') {
        res.writeHead(401); res.end('{}'); return;
    }
    const path = url.pathname;
    if (path === '/v2/analytics/timelines') {
        // Observed production failure: the legacy account metric returns no
        // daily points for modern dates. Keep this fixture to catch regressions.
        if (url.searchParams.get('network') === 'facebook' && url.searchParams.get('metric') === 'pageImpressions' && url.searchParams.get('from') >= '2024-10-15T00:00:00') {
            res.end(JSON.stringify({ data: [] })); return;
        }
        const expected = { facebook: url.searchParams.get('from') < '2024-10-15T00:00:00' ? 'pageImpressions' : 'page_media_view', instagram: 'views', tiktok: 'video_views' };
        if (url.searchParams.get('subject') !== 'account' || ![expected[url.searchParams.get('network')], {facebook:'pageFollows',instagram:'followers',tiktok:'followers_count'}[url.searchParams.get('network')]].includes(url.searchParams.get('metric'))) {
            res.writeHead(400); res.end('{}'); return;
        }
        res.end(JSON.stringify({ data: [{ metric: url.searchParams.get('metric'), values: [
            { dateTime: '2026-10-12T00:00:00', value: 1000 },
            { dateTime: '2026-10-15T00:00:00', value: 2000 }
        ] }] })); return;
    }
    if (path === '/stats/facebook/posts') {
        res.end(JSON.stringify([{ postId: 'fb-photo', created: { dateTime: '2026-10-15T10:12:00', timezone: 'America/Costa_Rica' }, impressions: 278 }])); return;
    }
    if (path === '/v2/analytics/reels/facebook') {
        if (url.searchParams.get('blogId') === 'malformed') {
            res.setHeader('Content-Type', 'text/csv'); res.end('Unknown,Date\nx,y\n'); return;
        }
        const reel = { reelId: 'fb-reel', description: 'Video octubre', created: { dateTime: '2026-10-12T10:40:00', timezone: 'America/Costa_Rica' }, blueReelsPlayCount: 206, postImpressionsUnique: 150, reelUrl: 'https://example.com/reel' };
        if (url.searchParams.get('blogId') === 'csv') {
            res.setHeader('Content-Type', 'text/csv');
            res.end('reelId,description,date,blueReelsPlayCount\nfb-reel,Video octubre,2026-10-12T10:40:00,206\n'); return;
        }
        res.end(JSON.stringify({ data: [reel] })); return;
    }
    res.writeHead(501); res.end('{}');
}).listen(process.env.PORT);

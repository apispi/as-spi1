// Static documentation site generator.
//
// Reads the single source of truth (resources/js/docs/content.js) and emits a
// browser-accessible static site into public_html/docs/ — one real HTML file
// per article, plus a landing page, a shared stylesheet, a client-side search
// index, and an .htaccess that lets Apache serve the directory statically
// (the app's front controller otherwise claims every path).
//
// Run via `npm run build` (which chains this after the Vite build), or
// directly with `node scripts/build-docs.mjs`.

import { DOCS, CATEGORIES } from '../resources/js/docs/content.js';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(__dirname, '../public_html/docs');

// ---------- helpers ----------

const escapeHtml = (s) => String(s)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;');

// Inline markers: [label](/path), `code`, **bold** — same set the app renders.
// Internal /docs/<slug> links get a trailing slash so they resolve to the
// generated directory without an extra redirect hop.
const inline = (text) => {
  let out = escapeHtml(text);
  out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, label, href) => {
    let h = href;
    const external = /^https?:\/\//i.test(h);
    if (!external && /^\/docs\/[a-z0-9-]+$/i.test(h)) h = h + '/';
    const attrs = external ? ' target="_blank" rel="noopener"' : '';
    return `<a href="${h.replace(/"/g, '&quot;')}"${attrs}>${label}</a>`;
  });
  out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  return out;
};

const slugAnchor = (text) => String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

const renderBlock = (b) => {
  switch (b.type) {
    case 'h2': return `<h2 id="${slugAnchor(b.text)}">${escapeHtml(b.text)}</h2>`;
    case 'h3': return `<h3 id="${slugAnchor(b.text)}">${escapeHtml(b.text)}</h3>`;
    case 'p': return `<p>${inline(b.text)}</p>`;
    case 'note': return `<div class="note">${inline(b.text)}</div>`;
    case 'ul': return `<ul>${b.items.map((i) => `<li>${inline(i)}</li>`).join('')}</ul>`;
    case 'ol': return `<ol>${b.items.map((i) => `<li>${inline(i)}</li>`).join('')}</ol>`;
    case 'code': return `<div class="code"><div class="code-head"><span>${escapeHtml(b.lang || 'text')}</span><button class="copy" type="button">Copy</button></div><pre><code>${escapeHtml(b.code)}</code></pre></div>`;
    default: return '';
  }
};

const catLabel = (id) => (CATEGORIES.find((c) => c.id === id)?.label || id);

// Sidebar shared by every page; marks the active article.
const sidebar = (activeSlug) => {
  const groups = CATEGORIES.map((cat) => {
    const items = DOCS.filter((d) => d.category === cat.id);
    if (!items.length) return '';
    const links = items.map((d) =>
      `<a href="/docs/${d.slug}/" class="nav-link${d.slug === activeSlug ? ' active' : ''}">${escapeHtml(d.title)}</a>`
    ).join('');
    return `<div class="nav-group"><div class="nav-cat">${escapeHtml(cat.label)}</div>${links}</div>`;
  }).join('');
  return `<aside class="sidebar"><nav>${groups}</nav></aside>`;
};

const shell = ({ title, description, activeSlug, main }) => `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)} · Spi docs</title>
<meta name="description" content="${escapeHtml(description || '')}">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/docs/styles.css">
<script>
  // Resolve theme before first paint (mirrors the app's spi-theme key).
  (function(){try{var t=localStorage.getItem('spi-theme');if(!t){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();
</script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="/docs/">
    <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    <span>Spi docs</span>
  </a>
  <div class="search"><input id="q" type="search" placeholder="Search the docs…" autocomplete="off" aria-label="Search"><div id="results" class="results" hidden></div></div>
  <div class="topbar-right">
    <button id="theme" class="icon-btn" type="button" aria-label="Toggle theme">◐</button>
    <a class="topbar-link" href="/">Open app →</a>
  </div>
</header>
<div class="layout">
  ${sidebar(activeSlug)}
  <main class="content">${main}</main>
</div>
<footer class="foot"><p>© ${new Date().getFullYear()} Spi · apispi.com · <a href="/developers">API reference</a> · <a href="/">Open the app</a></p></footer>
<script src="/docs/app.js" defer></script>
</body>
</html>`;

// ---------- pages ----------

const articlePage = (doc, index) => {
  const prev = index > 0 ? DOCS[index - 1] : null;
  const next = index < DOCS.length - 1 ? DOCS[index + 1] : null;
  const toc = doc.body.filter((b) => b.type === 'h2').map((b) =>
    `<a href="#${slugAnchor(b.text)}">${escapeHtml(b.text)}</a>`
  ).join('');

  const main = `
    <div class="crumb"><a href="/docs/">Docs</a><span>/</span><span>${escapeHtml(catLabel(doc.category))}</span></div>
    <h1>${escapeHtml(doc.title)}</h1>
    <p class="lead">${escapeHtml(doc.summary)}</p>
    ${toc ? `<nav class="toc"><span class="toc-label">On this page</span>${toc}</nav>` : ''}
    <article>${doc.body.map(renderBlock).join('\n')}</article>
    <nav class="pager">
      ${prev ? `<a class="pager-link prev" href="/docs/${prev.slug}/"><span>← Previous</span><strong>${escapeHtml(prev.title)}</strong></a>` : '<span></span>'}
      ${next ? `<a class="pager-link next" href="/docs/${next.slug}/"><span>Next →</span><strong>${escapeHtml(next.title)}</strong></a>` : '<span></span>'}
    </nav>`;

  return shell({ title: doc.title, description: doc.summary, activeSlug: doc.slug, main });
};

const indexPage = () => {
  const groups = CATEGORIES.map((cat) => {
    const items = DOCS.filter((d) => d.category === cat.id);
    if (!items.length) return '';
    const cards = items.map((d) =>
      `<a class="card" href="/docs/${d.slug}/"><span class="card-title">${escapeHtml(d.title)}</span><span class="card-sum">${escapeHtml(d.summary)}</span></a>`
    ).join('');
    return `<section class="cat"><h2>${escapeHtml(cat.label)}</h2><div class="cards">${cards}</div></section>`;
  }).join('');

  const main = `
    <h1>Spi documentation</h1>
    <p class="lead">Everything you need to test, monitor, and automate APIs and MCP agents with Spi. Pick a topic or search above.</p>
    ${groups}`;

  return shell({ title: 'Documentation', description: 'Guides for testing, monitoring and automating APIs and MCP agents with Spi.', activeSlug: null, main });
};

// ---------- assets ----------

const STYLES = `/* Generated — do not edit; see scripts/build-docs.mjs */
:root{--bg:#ffffff;--panel:#f6f8fa;--border:#d0d7de;--text:#1f2328;--muted:#59636e;--accent:#0969da;--accent-soft:rgba(9,105,218,.1)}
:root[data-theme="dark"]{--bg:#0d1117;--panel:#161b22;--border:#30363d;--text:#c9d1d9;--muted:#8b949e;--accent:#58a6ff;--accent-soft:rgba(88,166,255,.12)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:24px;padding:12px 20px;background:var(--bg);border-bottom:1px solid var(--border)}
.brand{display:flex;align-items:center;gap:8px;color:var(--text);font-weight:700}.brand svg{color:var(--accent)}
.search{flex:1;max-width:460px;position:relative}
.search input{width:100%;padding:8px 12px;border-radius:8px;background:var(--panel);border:1px solid var(--border);color:var(--text);font:inherit;font-size:14px}
.results{position:absolute;top:110%;left:0;right:0;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:6px;max-height:60vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25)}
.results a{display:block;padding:8px 10px;border-radius:7px;color:var(--text)}
.results a:hover,.results a.sel{background:var(--accent-soft);text-decoration:none}
.results .r-title{font-weight:600;font-size:14px}.results .r-sum{font-size:12.5px;color:var(--muted)}
.results .r-empty{padding:10px;color:var(--muted);font-size:13px}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-link{white-space:nowrap;font-size:14px}
.icon-btn{background:none;border:1px solid var(--border);color:var(--text);border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:15px}
.layout{display:flex;max-width:1180px;margin:0 auto;width:100%}
.sidebar{width:250px;flex-shrink:0;padding:26px 14px 60px;border-right:1px solid var(--border);position:sticky;top:59px;align-self:flex-start;max-height:calc(100vh - 59px);overflow-y:auto}
.nav-group{margin-bottom:20px}
.nav-cat{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:8px;padding-left:10px}
.nav-link{display:block;padding:6px 10px;border-radius:6px;color:var(--muted);font-size:14px}
.nav-link:hover{color:var(--text);background:var(--accent-soft);text-decoration:none}
.nav-link.active{color:var(--accent);background:var(--accent-soft);font-weight:600}
.content{flex:1;min-width:0;padding:30px 40px 90px;max-width:800px}
.crumb{display:flex;gap:8px;color:var(--muted);font-size:13px;margin-bottom:12px}
h1{font-size:32px;margin:0 0 8px}
.lead{font-size:17px;color:var(--muted);margin:0 0 22px}
.content h2{font-size:22px;margin:34px 0 12px;scroll-margin-top:74px}
.content h3{font-size:17px;margin:24px 0 10px;scroll-margin-top:74px}
.content p{margin:0 0 14px}
.content ul,.content ol{margin:0 0 16px;padding-left:24px}
.content li{margin-bottom:8px}
.content code{font-family:'Courier New',monospace;font-size:.88em;color:var(--accent);background:rgba(127,127,127,.14);padding:.1em .35em;border-radius:4px}
.note{border-left:3px solid var(--accent);background:var(--accent-soft);padding:12px 16px;border-radius:0 8px 8px 0;margin:0 0 16px;color:var(--muted);font-size:14px}
.note code,.note strong{color:var(--text)}
.toc{display:flex;flex-direction:column;gap:4px;border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin:0 0 24px;background:var(--panel)}
.toc-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:2px}
.toc a{font-size:13.5px}
.code{margin:0 0 18px;border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden;background:#0d1117}
.code-head{display:flex;justify-content:space-between;align-items:center;padding:6px 12px;background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08)}
.code-head span{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8b949e}
.copy{background:none;border:none;color:#58a6ff;font:inherit;font-size:12px;cursor:pointer}
.code pre{margin:0;padding:14px 16px;overflow-x:auto}
.code code{font-family:'Courier New',monospace;font-size:13px;color:#e5e7eb;background:none;padding:0;white-space:pre}
.pager{display:flex;justify-content:space-between;gap:16px;margin-top:44px;padding-top:22px;border-top:1px solid var(--border)}
.pager-link{display:flex;flex-direction:column;gap:4px;padding:12px 16px;border:1px solid var(--border);border-radius:10px;max-width:48%}
.pager-link.next{text-align:right;margin-left:auto}.pager-link:hover{border-color:var(--accent);text-decoration:none}
.pager-link span{font-size:12px;color:var(--muted)}.pager-link strong{color:var(--text);font-size:14px}
.cat{margin-bottom:32px}.cat h2{font-size:18px;margin:0 0 14px}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.card{display:flex;flex-direction:column;gap:4px;padding:14px 16px;border:1px solid var(--border);border-radius:10px;background:var(--panel)}
.card:hover{border-color:var(--accent);text-decoration:none}
.card-title{font-weight:600;color:var(--text)}.card-sum{font-size:13px;color:var(--muted)}
.foot{border-top:1px solid var(--border);padding:20px;text-align:center;color:var(--muted);font-size:13px}
.foot a{color:var(--muted)}
@media(max-width:820px){.sidebar{display:none}.content{padding:22px 18px 70px}.search{max-width:none}}
`;

const APPJS = `// Generated — theme toggle, copy buttons, client-side search.
(function(){
  var root=document.documentElement;
  var btn=document.getElementById('theme');
  if(btn)btn.addEventListener('click',function(){
    var cur=root.getAttribute('data-theme')==='light'?'dark':'light';
    root.setAttribute('data-theme',cur);
    try{localStorage.setItem('spi-theme',cur);}catch(e){}
  });
  document.querySelectorAll('.copy').forEach(function(b){
    b.addEventListener('click',function(){
      var pre=b.closest('.code').querySelector('code');
      navigator.clipboard.writeText(pre.textContent).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy';},1400);}).catch(function(){});
    });
  });
  var q=document.getElementById('q'),box=document.getElementById('results'),idx=null,sel=-1,items=[];
  function load(){if(idx)return Promise.resolve(idx);return fetch('/docs/search-index.json').then(function(r){return r.json();}).then(function(d){idx=d;return d;});}
  function render(list){
    items=list;sel=-1;
    if(!list.length){box.innerHTML='<div class="r-empty">No matches.</div>';box.hidden=false;return;}
    box.innerHTML=list.map(function(d){return '<a href="'+d.url+'"><div class="r-title">'+d.title+'</div><div class="r-sum">'+d.summary+'</div></a>';}).join('');
    box.hidden=false;
  }
  function search(term){
    term=term.trim().toLowerCase();if(!term){box.hidden=true;return;}
    load().then(function(d){render(d.filter(function(x){return x.text.indexOf(term)>-1;}).slice(0,12));});
  }
  if(q){
    q.addEventListener('input',function(){search(q.value);});
    q.addEventListener('keydown',function(e){
      var links=box.querySelectorAll('a');
      if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(sel+1,links.length-1);}
      else if(e.key==='ArrowUp'){e.preventDefault();sel=Math.max(sel-1,0);}
      else if(e.key==='Enter'&&links[sel]){location.href=links[sel].getAttribute('href');return;}
      else if(e.key==='Escape'){box.hidden=true;return;}
      links.forEach(function(l,i){l.classList.toggle('sel',i===sel);});
    });
    document.addEventListener('click',function(e){if(!e.target.closest('.search'))box.hidden=true;});
  }
})();
`;

const HTACCESS = `# Generated — serve this documentation directory as static files.
# The app's front controller (parent .htaccess) otherwise rewrites every path
# to index.php; turn that off here and index on the static landing page.
<IfModule mod_rewrite.c>
    RewriteEngine Off
</IfModule>
<IfModule mod_dir.c>
    DirectoryIndex index.html
</IfModule>
Options -MultiViews
`;

// ---------- write ----------

async function build() {
  // Clean previously generated output, then recreate.
  await rm(OUT, { recursive: true, force: true });
  await mkdir(OUT, { recursive: true });

  await writeFile(`${OUT}/styles.css`, STYLES);
  await writeFile(`${OUT}/app.js`, APPJS);
  await writeFile(`${OUT}/.htaccess`, HTACCESS);
  await writeFile(`${OUT}/index.html`, indexPage());

  const searchIndex = DOCS.map((d) => ({
    title: d.title,
    summary: d.summary,
    url: `/docs/${d.slug}/`,
    category: catLabel(d.category),
    text: (d.title + ' ' + d.summary + ' ' + JSON.stringify(d.body)).toLowerCase(),
  }));
  await writeFile(`${OUT}/search-index.json`, JSON.stringify(searchIndex));

  for (let i = 0; i < DOCS.length; i++) {
    const doc = DOCS[i];
    await mkdir(`${OUT}/${doc.slug}`, { recursive: true });
    await writeFile(`${OUT}/${doc.slug}/index.html`, articlePage(doc, i));
  }

  console.log(`Docs: generated ${DOCS.length} articles + landing into public_html/docs/`);
}

build().catch((e) => { console.error(e); process.exit(1); });

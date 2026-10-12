(function (root) {
  'use strict';

  const escapeHTML = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const safeLink = value => /^(?:https?:|mailto:|\/|#)/i.test(value) && !/^\/\//.test(value) ? value : '';

  function inline(value, siteURL = '') {
    let text = escapeHTML(String(value).replace(/\{\{site\.url\}\}/g, siteURL));
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
    text = text.replace(/!\[([^\]]*)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/g, (all, alt, rawUrl, title) => {
      const url = safeLink(rawUrl);
      return url ? `<img src="${escapeHTML(url)}" alt="${alt}"${title ? ` title="${escapeHTML(title)}"` : ''} loading="lazy">` : all;
    });
    text = text.replace(/\[([^\]]+)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/g, (all, label, rawUrl, title) => {
      const url = safeLink(rawUrl);
      return url ? `<a href="${escapeHTML(url)}" target="_blank" rel="noopener noreferrer"${title ? ` title="${escapeHTML(title)}"` : ''}>${label}</a>` : label;
    });
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_]+)__/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>').replace(/_([^_\n]+)_/g, '<em>$1</em>');
    return text.replace(/\{\{stats\.(auto|pc|mobile|total|docs)(?:\.(today|yesterday|total))?\}\}/g, (all, channel, period) =>
      `<span data-picapi-stat="${channel}" data-period="${period || 'total'}"></span>`);
  }

  function render(markdown, siteURL = '') {
    const safeSiteURL = String(siteURL || '').replace(/\/+$/, '');
    const renderInline = value => inline(value, safeSiteURL);
    let source = String(markdown || '').replace(/\r\n?/g, '\n').trim()
      .replace(/https?:\/\/(?:your-domain\.com|example\.com)(?=\/|[?#\s"'`]|$)/gi, '{{site.url}}');
    const raw = [], fences = [];
    source = source.replace(/```([\w+-]*)\n([\s\S]*?)```/g, (all, language, body) => {
      const token = `\u0000FENCE${fences.length}\u0000`;
      fences.push(`<pre><code class="language-${escapeHTML(language || 'text')}">${escapeHTML(body.replace(/\{\{site\.url\}\}/g, safeSiteURL).replace(/\n$/, ''))}</code></pre>`);
      return token;
    });
    source = source.replace(/<(style|script|section|article|header|footer|aside|div|table|figure|details|form|nav|main|p|ul|ol|blockquote|pre|h[1-6]|iframe|video|audio)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, block => {
      const token = `\u0000RAW${raw.length}\u0000`;
      raw.push(block.replace(/\{\{site\.url\}\}/g, escapeHTML(safeSiteURL)));
      return token;
    });

    const lines = source.split('\n'), output = [];
    const tokenLine = line => /^\u0000(?:RAW|FENCE)\d+\u0000$/.test(line.trim());
    const special = line => tokenLine(line) || /^(#{1,6})\s+/.test(line) || /^ {0,3}(?:[-*_])(?:\s*[-*_]){2,}\s*$/.test(line) || /^\s*(?:[-+*]|\d+[.)])\s+/.test(line) || /^\s*>/.test(line) || /^\s*\|/.test(line) || /^\s*</.test(line);

    for (let i = 0; i < lines.length;) {
      const line = lines[i];
      if (!line.trim()) { i++; continue; }
      const token = line.trim().match(/^\u0000(RAW|FENCE)(\d+)\u0000$/);
      if (token) { output.push(token[1] === 'RAW' ? raw[Number(token[2])] : fences[Number(token[2])]); i++; continue; }
      const heading = line.match(/^(#{1,6})\s+(.+)$/);
      if (heading) { const level = heading[1].length; output.push(`<h${level}>${renderInline(heading[2].trim())}</h${level}>`); i++; continue; }
      if (/^ {0,3}(?:[-*_])(?:\s*[-*_]){2,}\s*$/.test(line)) { output.push('<hr>'); i++; continue; }
      if (/^\s*>/.test(line)) {
        const items = [];
        while (i < lines.length && /^\s*>/.test(lines[i])) { items.push(renderInline(lines[i].replace(/^\s*>\s?/, '').trim())); i++; }
        output.push(`<blockquote><p>${items.join('<br>')}</p></blockquote>`); continue;
      }
      if (/^\s*(?:[-+*]|\d+[.)])\s+/.test(line)) {
        const ordered = /^\s*\d+[.)]/.test(line), items = [];
        while (i < lines.length) {
          const item = lines[i].match(/^\s*(?:[-+*]|\d+[.)])\s+(.+)$/);
          if (!item) break;
          items.push(`<li>${renderInline(item[1])}</li>`); i++;
        }
        output.push(`<${ordered ? 'ol' : 'ul'}>${items.join('')}</${ordered ? 'ol' : 'ul'}>`); continue;
      }
      if (/^\s*\|/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
        const rows = [];
        while (i < lines.length && /^\s*\|/.test(lines[i])) {
          const cells = lines[i].trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(cell => cell.trim());
          rows.push(cells); i++;
        }
        const header = rows.shift() || [];
        if (rows.length && rows[0].every(cell => /^:?-{2,}:?$/.test(cell))) rows.shift();
        output.push(`<div class="markdown-table-wrap"><table><thead><tr>${header.map(cell => `<th>${renderInline(cell)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${header.map((_, index) => `<td>${renderInline(row[index] || '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
        continue;
      }
      if (/^\s*</.test(line)) { output.push(line); i++; continue; }
      const paragraph = [line.trim()]; i++;
      while (i < lines.length && lines[i].trim() && !special(lines[i])) { paragraph.push(lines[i].trim()); i++; }
      output.push(`<p>${paragraph.map(renderInline).join('<br>')}</p>`);
    }
    return output.join('\n');
  }

  function frame(options) {
    const opt = options || {};
    const json = value => JSON.stringify(value).replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/&/g, '\\u0026');
    const siteURL = String(opt.baseURL || '').replace(/\/+$/, '');
    const base = escapeHTML(siteURL.replace(/\/?$/, '/'));
    const initial = json({ stats: opt.stats || { channels: {}, total: {} }, visits: opt.visits || {}, theme: opt.theme || 'light' });
    const palettes = opt.palettes || {};
    const colors = mode => {
      const palette = palettes[mode] || {};
      return `--doc-bg:${palette.background || (mode === 'dark' ? '#101827' : '#ffffff')};--doc-surface:${palette.surface || (mode === 'dark' ? '#141e2e' : '#ffffff')};--doc-line:${palette.line || (mode === 'dark' ? '#2b3b53' : '#dbe4ed')};--doc-muted:${palette.muted || (mode === 'dark' ? '#aebbd0' : '#65758b')};--doc-ink:${palette.text || (mode === 'dark' ? '#f5f7fb' : '#26384d')};--doc-accent:${palette.color || (mode === 'dark' ? '#8daeff' : '#2854da')};--doc-soft:color-mix(in srgb,var(--doc-surface) 84%,var(--doc-accent) 16%)`;
    };
    const runtime = `(()=>{const initial=${initial};let stats=initial.stats||{channels:{},total:{}},visits=initial.visits||{},visibility={auto:true,pc:true,mobile:true,total:true,docs:true},pending=[];const periods=['today','yesterday','total'];const channel=(name)=>{if(!['auto','pc','mobile'].includes(name))throw new TypeError('未知接口：'+name);return name};const period=(value)=>{if(!periods.includes(value))throw new TypeError('未知统计周期：'+value);return value};function update(){document.querySelectorAll('[data-picapi-stat]').forEach(node=>{const name=node.dataset.picapiStat,p=node.dataset.period||'total';if(!periods.includes(p))return;node.hidden=!visibility[name];const values=name==='docs'?visits:(name==='total'?stats.total:stats.channels[name]);node.textContent=Number(values&&values[p]||0).toLocaleString('zh-CN')});document.dispatchEvent(new CustomEvent('picapi:stats',{detail:{stats,visits,visibility:{...visibility}}}))}window.PicAPIDocs=Object.freeze({getChannelStats(name){return {...stats.channels[channel(name)]}},getTotalStats(){return {...stats.total}},getChannelCount(name,p='total'){return Number((stats.channels[channel(name)]||{})[period(p)]||0)},getTotalCount(p='total'){return Number((stats.total||{})[period(p)]||0)},getDocVisitCount(p='total'){return Number((visits||{})[period(p)]||0)},getDocVisitStats(){return {today:Number(visits.today)||0,yesterday:Number(visits.yesterday)||0,total:Number(visits.total)||0}},showChannelStats(name,visible=true){visibility[channel(name)]=Boolean(visible);update()},showTotalStats(visible=true){visibility.total=Boolean(visible);update()},showDocVisitStats(visible=true){visibility.docs=Boolean(visible);update()},refreshStats(){const requestId=Math.random().toString(36).slice(2);parent.postMessage({type:'picapi:docs',action:'refresh',requestId},'*');return new Promise((resolve,reject)=>pending.push({requestId,resolve,reject}))}});window.addEventListener('message',event=>{if(event.source!==parent)return;const data=event.data;if(data?.type==='picapi:docs'&&data.action==='stats'){stats=data.stats||stats;visits=data.visits||visits;update();pending.splice(0).forEach(item=>item.resolve({...stats.total}))}if(data?.type==='picapi:docs-theme'){document.documentElement.dataset.docTheme=data.theme==='dark'?'dark':'light'}});document.addEventListener('DOMContentLoaded',update)})()`;
    const font = String(opt.fontFamily || '').replace(/[^\p{L}\p{N}\s"',._-]/gu, '');
    const fontStyle = font ? `<style>body{font-family:${font} !important}</style>` : '';
    const extraHead = String(opt.headHTML || '');
    const customCSS = String(opt.customCSS || '');
    const customJS = String(opt.customJS || '');
    const footerHTML = String(opt.footerHTML || '');
    const publicCSS = `${base}docs/docs-markdown.css?v=demo-promoted-1`;
    const script = `<script>${runtime}<\/script>`;
    const safeBase = base || '/';
    const themeStyle = `<style>:root{${colors('light')}}:root[data-doc-theme="dark"]{${colors('dark')}}</style>`;
    return `<!doctype html><html lang="zh-CN" data-doc-theme="${opt.theme === 'dark' ? 'dark' : 'light'}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="${safeBase}"><link rel="stylesheet" href="${escapeHTML(publicCSS)}">${themeStyle}${fontStyle}${customCSS ? `<style>${customCSS}</style>` : ''}${extraHead}${script}</head><body><main class="markdown-body">${render(opt.markdown || '', siteURL)}</main>${footerHTML}${customJS ? `<script>${customJS}<\/script>` : ''}</body></html>`;
  }

  root.PicAPIDocsMarkdown = Object.freeze({ render, frame });
})(window);

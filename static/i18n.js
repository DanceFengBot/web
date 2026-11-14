/* DanceFeng Web i18n: DOM translator.
 * English dictionary lives in template/lang/en_us.php and is injected as
 * window.DFB.i18n['en-US'] by index.php.
 */
(function () {
  'use strict';
  var LANG_KEY = 'dfb_lang';
  var currentLang = 'zh-CN';
  var originalTitle = '';
  var EN = (window.DFB && window.DFB.i18n && window.DFB.i18n['en-US']) || {};

  function getLang() {
    try { return currentLang = (window.localStorage.getItem(LANG_KEY) === 'en-US' ? 'en-US' : 'zh-CN'); }
    catch (e) { return currentLang; }
  }

  function translateText(value) {
    if (currentLang !== 'en-US' || typeof value !== 'string') return value;
    var m = value.match(/^(\s*)([\s\S]*?)(\s*)$/);
    if (!m) return value;
    var lead = m[1], text = m[2], trail = m[3];
    var key = text.trim();
    if (!key) return value;
    var out = EN[key];
    if (!out && text.indexOf('\n') !== -1) {
      var lines = text.split('\n');
      var changed = false;
      lines = lines.map(function (line) {
        var translated = translateText(line);
        if (translated !== line) { changed = true; return translated; }
        return line;
      });
      if (changed) {
        return lead + lines.join('\n') + trail;
      }
    }
    if (!out) {
      out = key
        .replace(/^共 (\d+) 台，其中在线 (\d+) 台$/, 'Total $1 · $2 online')
        .replace(/^共 (\d+) 条留言$/, '$1 messages')
        .replace(/^共 (\d+) 条$/, '$1 items')
        .replace(/^第 (\d+) \/ (\d+) 页$/, 'Page $1 / $2')
        .replace(/^已登录 · 舞立方 ID (\d+)$/, 'Signed in · DanceCube ID $1')
        .replace(/^舞立方用户 (\d+)$/, 'DanceCube User $1')
        .replace(/^当前版本 (.*)$/, 'Current version $1')
        .replace(/^Web 版本 (.*)$/, 'Web version $1')
        .replace(/^App 版本 (.*)$/, 'App version $1')
        .replace(/^「(.+)」的机台列表$/, '$1 · Machines')
        .replace(/^排除列表（(\d+)）$/, 'Excluded List ($1)')
        .replace(/^场地排除审核（(\d+)）$/, 'Venue Exclusion Review ($1)')
        .replace(/^展开排除列表（(\d+)）$/, 'Expand Excluded List ($1)')
        .replace(/^收起排除列表（(\d+)）$/, 'Collapse Excluded List ($1)')
        .replace(/^共 (\d+) 个已登录用户$/, '$1 signed-in users')
        .replace(/^(\d+) 个已登录用户$/, '$1 signed-in users')
        .replace(/^第 (\d+) 页 · 共 (\d+) 页$/, 'Page $1 of $2')
        .replace(/^(.+)（(\d+) 位）$/, '$1 ($2)')
        .replace(/^当前账号：舞立方 ID (\d+)$/, 'Current account: DanceCube ID $1')
        .replace(/^(\d+) 个字符$/, '$1 characters')
        .replace(/^(\d+) 分钟$/, '$1 min')
        .replace(/^(\d+) 秒$/, '$1 sec')
        .replace(/^(\d+) 天$/, '$1 days')
        .replace(/^(.+) · 共 (\d+) 页$/, '$1 · $2 pages')
        .replace(/^等级 (\d+)$/, 'Level $1')
        .replace(/^第 (\d+) \/ (\d+) 页$/, 'Page $1 / $2')
        .replace(/^身份标识（(.+)）保存在浏览器 Cookie 里，只用于在本站区分不同访问者的登录凭证。$/, 'Identity ($1) is stored in a browser cookie and only identifies visitors to this site.')
        .replace(/^手机号：(.+)$/, 'Phone: $1')
        .replace(/^验证码已发送至 (.+)$/, 'Code sent to $1')
        .replace(/^已登录 · 舞立方 ID (\d+)$/, 'Signed in · DanceCube ID $1')
        .replace(/^共 (\d+) 个已登录用户$/, '$1 signed-in users')
        .replace(/^(\d+) 个已登录用户$/, '$1 signed-in users')
        .replace(/^按地区查找附近机台.*$/, 'Find nearby machines by region (equivalent to “find DanceCube (region)”).')
        .replace(/^用 refresh token 换新.*$/, 'Refresh with a refresh token (equivalent to “#refresh”).')
        .replace(/^用 refresh_token 换新.*$/, 'Refresh with a refresh token (equivalent to “#refresh”).')
        .replace(/^查看 \/ 设置账号标识为 0 的默认凭证.*$/, 'View/set the default credential with account ID 0.');
    }
    return out === key ? value : lead + out + trail;
  }

  function skip(node) {
    var el = node.nodeType === 1 ? node : node.parentElement;
    if (!el) return false;
    if (el.closest('script,style,[data-no-i18n],.dfb-message-content,.dfb-review-content')) return true;
    return false;
  }

  function translateTextNode(node) {
    if (currentLang === 'en-US') {
      var original = node.__dfbOriginal;
      var current = node.nodeValue;
      if (original === undefined || current !== translateText(original)) {
        // 只在当前文本仍为中文时记录原文，避免把英文译文当原文
        if (/[\u4e00-\u9fff]/.test(current)) {
          node.__dfbOriginal = current;
          var out = translateText(current);
          if (out !== current) node.nodeValue = out;
        }
      }
    } else if (node.__dfbOriginal !== undefined) {
      node.nodeValue = node.__dfbOriginal;
      delete node.__dfbOriginal;
    }
  }

  function translateAttrs(el) {
    ['placeholder', 'title', 'aria-label'].forEach(function (name) {
      if (!el.hasAttribute || !el.hasAttribute(name)) return;
      var key = '__dfbOrig_' + name;
      if (currentLang === 'en-US') {
        var value = el.getAttribute(name);
        if (value && /[\u4e00-\u9fff]/.test(value)) {
          if (!el[key]) el[key] = value;
          var translated = translateText(value);
          if (translated !== value) el.setAttribute(name, translated);
        }
      } else if (el[key]) {
        el.setAttribute(name, el[key]);
        delete el[key];
      }
    });
  }

  function walk(root) {
    if (!root || skip(root)) return;
    if (root.nodeType === 3) {
      translateTextNode(root);
      return;
    }
    if (root.nodeType !== 1 && root.nodeType !== 9) return;
    if (root.nodeType === 1) translateAttrs(root);
    var child = root.firstChild;
    while (child) {
      walk(child);
      child = child.nextSibling;
    }
  }

  var translating = false;
  function translateDom(root) {
    if (translating) return;
    translating = true;
    try { walk(root || document.body); } finally { translating = false; }
  }

  function setLanguage(lang) {
    currentLang = lang === 'en-US' ? 'en-US' : 'zh-CN';
    try { window.localStorage.setItem(LANG_KEY, currentLang); } catch (e) {}
    document.documentElement.setAttribute('lang', currentLang === 'en-US' ? 'en' : 'zh-CN');
    if (currentLang === 'en-US') {
      document.title = 'DanceFeng · DanceCube Assistant';
    } else if (originalTitle) {
      document.title = originalTitle;
    }
    translateDom(document.body);
    var btn = document.getElementById('dfbLangBtn');
    if (btn) btn.textContent = currentLang === 'en-US' ? '中文' : 'EN';
  }

  function init() {
    getLang();
    originalTitle = document.title;
    document.documentElement.setAttribute('lang', currentLang === 'en-US' ? 'en' : 'zh-CN');
    if (currentLang === 'en-US') document.title = 'DanceFeng · DanceCube Assistant';
    var btn = document.getElementById('dfbLangBtn');
    if (btn) {
      btn.setAttribute('data-no-i18n', '1');
      btn.textContent = currentLang === 'en-US' ? '中文' : 'EN';
      btn.addEventListener('click', function () {
        setLanguage(currentLang === 'en-US' ? 'zh-CN' : 'en-US');
      });
    }
    translateDom(document.body);
    var root = document.getElementById('dfbRoot') || document.body;
    if (window.MutationObserver) {
      new MutationObserver(function () {
        if (currentLang === 'en-US') translateDom(root);
      }).observe(root, { childList: true, subtree: true, characterData: true });
    }
  }

  window.DFB_I18N = {
    getLanguage: function () { return currentLang; },
    setLanguage: setLanguage,
    translateDom: translateDom,
    translateText: translateText
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

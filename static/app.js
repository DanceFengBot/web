/* ==========================================================================
 * 舞小枫 · 舞立方助手（独立网页版）前端逻辑
 *
 * 由 Discuz 插件版移植而来：所有数据仍由本站 PHP 直接向舞立方接口拉取，
 * 页面拿到结构化数据后渲染成卡片 / 列表，**不生成图片**。
 *
 * 与插件版的区别：
 *   1. 身份由浏览器 Cookie 自动分配（window.DFB），不需要论坛登录；
 *   2. 所有请求都走同源接口 api.php?act=...；
 *   3. 默认开放常规功能；「后台管理」只对 config.php 里 admin_user_ids
 *      指定的舞立方账号开放，首次进入必须设置后台密码，之后需输入密码。
 * ========================================================================== */

(function () {
  'use strict';

  var CFG = window.DFB || {};
  var PERMS = CFG.perms || {};
  var LOGGED_IN = !!CFG.loggedIn;
  var API_URL = CFG.apiUrl || 'api.php';
  var PLUGIN_URL = CFG.pluginUrl || 'index.php';
  var ANNOUNCEMENT = CFG.announcement || null;
  var ANNOUNCEMENT_DISMISS_KEY = 'dfb_announcement_dismissed';
  var THEME_KEY = 'dfb_theme';
  var currentDark = false;

  /* ================================================================ 主题 */
  function isDarkTheme() {
    try {
      return window.localStorage.getItem(THEME_KEY) === 'dark';
    } catch (e) {
      return false;
    }
  }

  function applyTheme(dark, persist) {
    currentDark = !!dark;
    var root = document.getElementById('dfbRoot');
    if (root) root.classList.toggle('dfb-dark', !!dark);
    if (document.body) document.body.classList.toggle('dfb-dark', !!dark);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#15121e' : '#f7f8fc');
    var btn = document.getElementById('dfbThemeBtn');
    if (btn) {
      btn.textContent = dark ? '☀️ 亮色' : '🌙 暗色';
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    if (persist) {
      try {
        window.localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
      } catch (e) {
        // 隐私模式只应用当前会话
      }
    }
  }

  function initTheme() {
    var dark = isDarkTheme();
    applyTheme(dark, false);
    var btn = document.getElementById('dfbThemeBtn');
    if (btn) {
      btn.addEventListener('click', function () {
        applyTheme(!currentDark, true);
      });
    }
  }

  /* ================================================================ 基础工具 */
  function $(sel) { return document.querySelector(sel); }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) return;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key === 'html') node.innerHTML = value;
      else if (key === 'style') node.setAttribute('style', value);
      else if (key.indexOf('on') === 0 && typeof value === 'function') {
        node.addEventListener(key.slice(2).toLowerCase(), value);
      } else node.setAttribute(key, value);
    });
    var list = [].concat(children === undefined ? [] : children);
    list.forEach(function (child) {
      if (child === null || child === undefined || child === false) return;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function card(title, sub, children) {
    var kids = [];
    if (title) kids.push(el('h3', { class: 'dfb-card-title', text: title }));
    if (sub) kids.push(el('p', { class: 'dfb-card-sub', text: sub }));
    [].concat(children || []).forEach(function (c) { kids.push(c); });
    return el('div', { class: 'dfb-card' }, kids);
  }

  function field(labelText, inputNode) {
    return el('div', { class: 'dfb-field' }, [
      el('label', { text: labelText }),
      inputNode
    ]);
  }

  function alertBox(kind, text) {
    return el('div', { class: 'dfb-alert ' + kind, text: text });
  }

  function emptyBox(text) {
    return el('div', { class: 'dfb-empty', text: text });
  }

  function kvCard(title, pairs) {
    return card(title, null, [
      el('div', { class: 'dfb-kv' }, pairs.map(function (pair) {
        return el('div', { class: 'dfb-kv-row' }, [
          el('span', { text: pair[0] }),
          el('span', { text: show(pair[1]) })
        ]);
      }))
    ]);
  }

  function spacer(height) {
    return el('div', { style: 'height:' + height + 'px' });
  }

  function listResult(items, renderer) {
    if (!items || !items.length) return emptyBox('没有拿到数据');
    return el('div', { style: 'display:grid;gap:10px' }, items.map(renderer));
  }

  /* 空值 / 数字格式化 */
  function show(value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'number') {
      if (isFinite(value) && Math.abs(value - Math.round(value)) > 0.000001) {
        return String(Math.round(value * 100) / 100);
      }
      return String(value);
    }
    return String(value);
  }

  function fmtInt(value) {
    var n = Number(value);
    if (!isFinite(n)) return show(value);
    return n.toLocaleString('en-US');
  }

  /* ================================================================ 公告 */
  function announcementDismissed(id) {
    if (!id) return false;
    try {
      return window.localStorage.getItem(ANNOUNCEMENT_DISMISS_KEY) === String(id);
    } catch (e) {
      return false;
    }
  }

  function dismissAnnouncement(id) {
    try {
      window.localStorage.setItem(ANNOUNCEMENT_DISMISS_KEY, String(id));
    } catch (e) {
      // 隐私模式 / 禁用 localStorage 时只关闭当前页面
    }
  }

  function renderAnnouncement() {
    var box = $('#dfbAnnouncement');
    if (!box) return;
    box.innerHTML = '';
    var data = ANNOUNCEMENT;
    if (!data || !data.content) {
      box.hidden = true;
      return;
    }
    if (data.closeable && announcementDismissed(data.id)) {
      box.hidden = true;
      return;
    }

    var head = el('div', { class: 'dfb-announcement-head' }, [
      el('span', { class: 'dfb-announcement-badge', text: '公告' }),
      el('span', {
        class: 'dfb-announcement-time',
        text: data.updated ? new Date(Number(data.updated) * 1000).toLocaleString() : ''
      })
    ]);
    if (data.closeable) {
      head.appendChild(el('button', {
        class: 'dfb-announcement-close',
        type: 'button',
        title: '关闭公告',
        text: '×',
        onclick: function () {
          dismissAnnouncement(data.id);
          box.hidden = true;
        }
      }));
    }

    box.className = 'dfb-announcement' + (data.closeable ? '' : ' important');
    box.appendChild(head);
    box.appendChild(el('div', { class: 'dfb-announcement-content', text: String(data.content) }));
    box.hidden = false;
  }

  function setAnnouncement(data) {
    ANNOUNCEMENT = data || null;
    CFG.announcement = ANNOUNCEMENT;
    renderAnnouncement();
  }

  /* =================================================================== Toast */
  function toast(message, kind) {
    var box = $('#dfbToasts');
    if (!box) return;
    var node = el('div', { class: ('dfb-toast ' + (kind || '')).replace(/\s+$/, ''), text: message });
    box.appendChild(node);
    setTimeout(function () {
      node.style.transition = 'opacity .25s, transform .25s';
      node.style.opacity = '0';
      node.style.transform = 'translateY(-8px)';
      setTimeout(function () { if (node.parentNode) node.parentNode.removeChild(node); }, 260);
    }, 2600);
  }

  /* ================================================================= Loading */
  var loadingDepth = 0;
  var loadingWatchdog = null;

  function forceHideLoading() {
    loadingDepth = 0;
    if (loadingWatchdog) { clearTimeout(loadingWatchdog); loadingWatchdog = null; }
    var mask = $('#dfbLoading');
    if (!mask) return;
    mask.hidden = true;
    mask.style.display = 'none';
  }

  function showLoading(text) {
    loadingDepth += 1;
    var mask = $('#dfbLoading');
    if (!mask) return;
    var label = $('#dfbLoadingText');
    if (label) label.textContent = text || '小枫正在计算中,等一下下…';
    mask.hidden = false;
    mask.style.display = '';
    if (loadingWatchdog) clearTimeout(loadingWatchdog);
    loadingWatchdog = setTimeout(forceHideLoading, 180000);
  }

  function hideLoading() {
    loadingDepth = Math.max(0, loadingDepth - 1);
    if (loadingDepth === 0) forceHideLoading();
  }

  function withLoading(fn, text) {
    showLoading(text);
    var result;
    try {
      result = fn();
    } catch (error) {
      hideLoading();
      throw error;
    }
    if (result && typeof result.then === 'function') {
      return result.then(function (value) { hideLoading(); return value; },
        function (error) { hideLoading(); throw error; });
    }
    hideLoading();
    return result;
  }

  /* ============================================================== HTTP 封装 */
  function buildUrl(act, params) {
    var search = new URLSearchParams();
    search.set('act', act);
    if (CFG.formhash) search.set('formhash', CFG.formhash);
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === '') return;
      search.set(key, value);
    });
    return API_URL + (API_URL.indexOf('?') === -1 ? '?' : '&') + search.toString();
  }

  function request(act, options) {
    options = options || {};
    var method = options.method || 'GET';
    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (options.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      var payload = {};
      if (CFG.formhash) payload.formhash = CFG.formhash;
      Object.keys(options.body || {}).forEach(function (key) { payload[key] = options.body[key]; });
      init.body = JSON.stringify(payload);
    }
    return fetch(buildUrl(act, options.params), init).then(function (response) {
      return response.text().then(function (raw) {
        var text = raw ? String(raw).replace(/^\uFEFF/, '').replace(/^\s+/, '') : '';
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }

        if (!response.ok) {
          var detail = null;
          if (data && (data.detail || data.message)) detail = data.detail || data.message;
          if (Array.isArray(detail)) {
            detail = detail.map(function (d) { return d.msg || JSON.stringify(d); }).join('；');
          }
          if (!detail || typeof detail !== 'string') {
            // 非 JSON 响应（服务器错误页 / PHP 警告 / 代理错误页）不直接抛给用户，
            // 否则会把一段 HTML 或乱码当成错误信息显示
            if (text) console.error('[dancefengbot] 非 JSON 响应（HTTP ' + response.status + '）：', text.slice(0, 500));
            detail = /^</.test(text)
              ? '服务器返回了非预期的页面（HTTP ' + response.status + '），请刷新页面后重试。'
              : '请求失败（HTTP ' + response.status + '），请稍后重试。';
          }
          var error = new Error(detail.slice(0, 300));
          /* 把结构化数据（例如 admin 状态 / loginRequired）挂到错误上 */
          error.data = data;
          if (data && data.siteClosed) {
            CFG.siteClosed = true;
            setTimeout(function () {
              buildNav();
              renderRoute();
            }, 0);
          }
          if (data && data.loginRequired) {
            error.loginRequired = true;
            /* 本地还留着过期凭证时，立即切回游客界面 */
            if (LOGGED_IN) {
              LOGGED_IN = false;
              renderUserState({ loggedIn: false });
              buildNav();
            }
            /* 游客使用需要登录的功能：直接回到站内登录页，避免浏览器 HTTP 认证弹框 */
            setTimeout(function () {
              if (currentRoute() !== '/login') location.hash = '#/login';
              else renderRoute();
            }, 0);
          }
          throw error;
        }

        if (!data) {
          if (text) console.error('[dancefengbot] 响应不是合法 JSON：', text.slice(0, 500));
          throw new Error('服务器返回了无法识别的内容，请刷新页面后重试。');
        }
        return data;
      });
    });
  }

  /* ================================================================== 权限 */
  function canUse(feature) {
    if (CFG.banned) return false;
    /* 站点关闭：非管理员只保留登录 */
    if (CFG.siteClosed && !isAdminUser() && feature !== 'login') return false;
    /* 游客只允许登录：其它功能 / 页面一律不可用 */
    if (!LOGGED_IN) return feature === 'login';
    if (!feature) return true;
    return !!PERMS[feature];
  }

  function isAdminUser() {
    return !!(CFG.admin && CFG.admin.eligible);
  }

  function featureName(feature) {
    var names = CFG.permNames || {};
    return names[feature] || feature;
  }

  /* ================================================================ 页面表 */
  var TILE_DESC = {
    '/login': '微信扫码或手机号验证，二选一',
    '/user-info': '个人信息面板，也可查看他人',
    '/ratio': 'B15/R15 · B30 · AP30 战力分析',
    '/level-scores': '按等级查看成绩，每页 30 首',
    '/team': '战队信息 / 成员列表',
    '/messages': '留言、建议与反馈',
    '/changelog': '版本更新记录',
    '/closed': '站点维护中',
    '/reply-item': '胜率 / 游玩次数 / 通过歌曲',
    '/machine': '按地区查找附近的机台',
    '/gain-code': '批量兑换自制谱兑换码',
    '/token': '查看 Token、强制刷新',
    '/token-admin': '后台管理：Token / 客户端 ID（需密码）',
    '/perms': '查看当前浏览器身份与功能状态',
    '/help': '功能与指令的对应关系'
  };

  var ROUTES = {
    '/': { title: '功能总览', desc: '舞立方助手「舞小枫」网页版，点选下方任一功能即可使用。', feature: null, mount: mountHome },
    '/login': { title: '账号登录', desc: '微信扫码或手机号 + 图形验证码 + 短信验证码，二选一即可绑定舞立方账号。', feature: 'login', mount: mountLogin },
    '/logout': { title: '退出登录', desc: '删除本账号保存的登录凭证。', feature: 'login', mount: mountLogout },
    '/user-info': { title: '个人信息', desc: '展示战力 / 积分 / 天梯段位 / 金币等数据，也可以查看他人。', feature: 'data', mount: mountUserInfo },
    '/ratio': { title: '战力分析', desc: 'B15 / R15、B30、AP30 三种战力视图，直接列出成绩明细。', feature: 'data', mount: mountRatio },
    '/level-scores': { title: '分数列表', desc: '按歌曲等级查看成绩列表，每页 30 首。', feature: 'data', mount: mountLevelScores },
    '/team': { title: '我的战队', desc: '查看当前账号所在战队的信息、战力与成员列表。', feature: 'data', mount: mountTeam },
    '/messages': { title: '留言板', desc: '在这里留下你的建议、反馈或想说的话。', feature: 'data', mount: mountMessages },
    '/changelog': { title: '更新日志', desc: '查看舞小枫各版本的更新内容。', feature: 'data', mount: mountChangelog },
    '/closed': { title: '站点维护中', desc: '站点暂时关闭，仅保留公告、登录与退出。', feature: null, mount: mountSiteClosed },
    '/reply-item': { title: '数据统计', desc: '个人胜率、游玩次数、通过歌曲等统计数据（对应 myri）。', feature: 'data', mount: mountReplyItem },
    '/machine': { title: '查找机台', desc: '按地区查找附近的舞立方机台。', feature: 'query', mount: mountMachine },
    '/gain-code': { title: '兑换码', desc: '批量兑换自制谱兑换码（15 位字母数字）。', feature: 'query', mount: mountGainCode },
    '/token': { title: '查看 / 刷新 Token', desc: '查看本账号或默认账号的 Token，也可以强制刷新。', feature: 'token', mount: mountToken },
    '/token-admin': { title: '后台管理', desc: '仅指定舞立方账号可见；首次进入需设置密码，之后需输入密码。', feature: 'admin', mount: mountTokenAdmin },
    '/perms': { title: '我的身份', desc: '查看当前浏览器身份、数据存储方式与功能状态。', feature: null, mount: mountPerms },
    '/help': { title: '功能说明', desc: '网页版与原 QQ 端指令的对应关系。', feature: null, mount: mountHelp }
  };

  var NAV = [
    {
      title: '我的数据',
      items: [
        { route: '/user-info', ico: '👤', label: '个人信息', feature: 'data' },
        { route: '/ratio', ico: '📊', label: '战力分析', feature: 'data' },
        { route: '/level-scores', ico: '🎵', label: '分数列表', feature: 'data' },
        { route: '/team', ico: '🛡️', label: '我的战队', feature: 'data' },
        { route: '/reply-item', ico: '📈', label: '数据统计', feature: 'data' }
      ]
    },
    {
      title: '查询',
      items: [
        { route: '/machine', ico: '🕹️', label: '查找机台', feature: 'query' },
        { route: '/gain-code', ico: '🎁', label: '兑换码', feature: 'query' }
      ]
    },
    {
      title: 'Token',
      items: [
        { route: '/token', ico: '🔑', label: '查看 / 刷新', feature: 'token' },
        { route: '/token-admin', ico: '⚙️', label: '后台管理', feature: 'admin' }
      ]
    },
    {
      title: '其它',
      items: [
        { route: '/perms', ico: '🛡️', label: '我的身份', feature: null },
        { route: '/messages', ico: '💬', label: '留言板', feature: 'data' },
        { route: '/changelog', ico: '📝', label: '更新日志', feature: 'data' },
        { route: '/help', ico: '❓', label: '功能说明', feature: null }
      ]
    }
  ];

  function visibleNav() {
    var groups = [];
    NAV.forEach(function (group) {
      var items = group.items.filter(function (item) { return canUse(item.feature); });
      if (items.length) groups.push({ title: group.title, items: items });
    });
    return groups;
  }

  function updateGuestChrome() {
    var foot = $('#dfbSidebarFoot');
    if (foot) foot.hidden = !LOGGED_IN;
  }

  function buildNav() {
    var nav = $('#dfbNav');
    buildMobileNav();
    updateGuestChrome();
    if (!nav) return;
    nav.innerHTML = '';
    /* 游客：侧边栏所有功能隐藏，只保留「当前用户」框里的登录入口 */
    if (!LOGGED_IN) {
      nav.hidden = true;
      return;
    }
    /* 关闭站点时，非管理员不显示任何功能入口 */
    if (CFG.siteClosed && !isAdminUser()) {
      nav.hidden = true;
      return;
    }
    nav.hidden = false;
    var groups = visibleNav();
    if (!groups.length) {
      nav.appendChild(el('div', { class: 'dfb-empty', text: '暂无可用的功能' }));
      return;
    }
    groups.forEach(function (group) {
      var wrap = el('div', { class: 'dfb-nav-group' }, [
        el('div', { class: 'dfb-nav-group-title', text: group.title })
      ]);
      group.items.forEach(function (item) {
        wrap.appendChild(el('a', {
          href: PLUGIN_URL + '#' + item.route,
          'data-route': item.route,
          class: 'dfb-nav-link'
        }, [
          el('span', { class: 'dfb-ico', text: item.ico }),
          item.label
        ]));
      });
      nav.appendChild(wrap);
    });
  }

  /* ------------------------------------------------------- 手机底部导航 */
  function isMobileLayout() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function defaultHomeRoute() {
    return isMobileLayout() ? '/user-info' : '/';
  }

  function buildMobileNav() {
    var nav = $('#dfbMobileNav');
    if (!nav) return;
    nav.innerHTML = '';
    if (!LOGGED_IN || (CFG.siteClosed && !isAdminUser())) {
      nav.hidden = true;
      return;
    }
    var candidates = [
      { route: '/user-info', ico: '👤', label: '个人信息', feature: 'data' },
      { route: '/ratio', ico: '📊', label: '战力分析', feature: 'data' },
      { route: '/level-scores', ico: '🎵', label: '分数列表', feature: 'data' },
      { route: '/team', ico: '🛡️', label: '我的战队', feature: 'data' },
      { route: '/machine', ico: '🕹️', label: '查找机台', feature: 'query' },
      { route: '/messages', ico: '💬', label: '留言板', feature: 'data' },
      { route: '/gain-code', ico: '🎁', label: '兑换码', feature: 'query' },
      { route: '/', ico: '⋯', label: '更多', feature: null }
    ];
    var items = candidates.filter(function (item) { return canUse(item.feature); });
    if (!items.length) {
      nav.hidden = true;
      return;
    }
    nav.hidden = false;
    var activeRoute = currentRoute();
    var known = {};
    items.forEach(function (item) { known[item.route] = item; });
    if (!known[activeRoute]) activeRoute = '/';
    items.forEach(function (item) {
      var btn = el('button', {
        class: 'dfb-mobile-nav-item' + (item.route === activeRoute ? ' active' : ''),
        type: 'button',
        'data-route': item.route,
        onclick: function () {
          if (btn.__dfbDragged) { btn.__dfbDragged = false; return; }
          location.hash = '#' + item.route;
        }
      }, [
        el('span', { class: 'dfb-ico', text: item.ico }),
        el('span', { text: item.label })
      ]);
      nav.appendChild(btn);
    });
    var active = nav.querySelector('.dfb-mobile-nav-item.active');
    if (active && active.scrollIntoView) {
      try { active.scrollIntoView({ block: 'nearest', inline: 'center' }); } catch (e) {}
    }
  }

  function initMobileNavDrag() {
    var nav = $('#dfbMobileNav');
    if (!nav) return;
    var dragging = false, startX = 0, startLeft = 0, moved = 0;
    nav.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      dragging = true;
      moved = 0;
      startX = e.clientX;
      startLeft = nav.scrollLeft;
      nav.classList.add('is-dragging');
      try { nav.setPointerCapture(e.pointerId); } catch (err) {}
    });
    nav.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var delta = e.clientX - startX;
      if (Math.abs(delta) > 3) moved = Math.abs(delta);
      nav.scrollLeft = startLeft - delta;
      if (moved > 3) e.preventDefault();
    });
    function endDrag() {
      if (!dragging) return;
      dragging = false;
      nav.classList.remove('is-dragging');
      Array.prototype.forEach.call(nav.querySelectorAll('.dfb-mobile-nav-item'), function (btn) {
        btn.__dfbDragged = moved > 6;
      });
      setTimeout(function () {
        Array.prototype.forEach.call(nav.querySelectorAll('.dfb-mobile-nav-item'), function (btn) {
          btn.__dfbDragged = false;
        });
      }, 0);
    }
    nav.addEventListener('pointerup', endDrag);
    nav.addEventListener('pointercancel', endDrag);
    nav.addEventListener('pointerleave', endDrag);
  }

  /* ============================================================ 数据视图组件 */

  /* 带占位兜底的图片（头像 / 封面），加载失败时不显示破图 */
  function thumb(src, alt, placeholderText, extraClass) {
    var wrap = el('div', { class: 'dfb-thumb ' + (extraClass || '') }, [
      el('span', { class: 'dfb-thumb-ph', text: placeholderText || '' })
    ]);
    if (src) {
      var img = el('img', { src: src, alt: alt || '', loading: 'lazy', referrerpolicy: 'no-referrer' });
      img.addEventListener('load', function () { wrap.classList.add('has-img'); });
      img.addEventListener('error', function () {
        wrap.classList.remove('has-img');
        if (img.parentNode) img.parentNode.removeChild(img);
      });
      wrap.appendChild(img);
    }
    return wrap;
  }

  function statGrid(items) {
    return el('div', { class: 'dfb-stats' }, items.map(function (pair) {
      return el('div', { class: 'dfb-stat' }, [
        el('div', { class: 'dfb-stat-value', text: show(pair[1]) }),
        el('div', { class: 'dfb-stat-label', text: pair[0] })
      ]);
    }));
  }

  function sectionTitle(text, note) {
    return el('div', { class: 'dfb-section-title' }, [
      el('strong', { text: text }),
      note ? el('span', { class: 'dfb-section-note', text: note }) : null
    ]);
  }

  function gradeChip(grade) {
    return el('span', { class: 'dfb-grade grade-' + String(grade || 'D').replace(/[^A-Za-z_]/g, ''), text: grade || '-' });
  }

  function songCard(song) {
    var cover = thumb(song.cover, song.name, (song.name || '?').slice(0, 1), 'dfb-song-cover');
    cover.insertBefore(el('span', { class: 'dfb-song-lv', text: 'Lv.' + show(song.level) }), cover.firstChild);

    var diff = Number(song.diff) || 0;
    var diffClass = diff > 0 ? 'up' : (diff < 0 ? 'down' : 'flat');
    var diffText = diff > 0 ? '+' + diff : String(diff);

    var metaBits = ['ACC ' + Number(song.acc || 0).toFixed(2) + '%'];
    metaBits.push('COMBO ' + fmtInt(song.combo));
    metaBits.push('MISS ' + fmtInt(song.miss));

    var tags = [gradeChip(song.grade)];
    if (song.fullCombo && song.grade !== 'AP') tags.push(el('span', { class: 'dfb-tag fc', text: 'FC' }));

    return el('div', { class: 'dfb-song' }, [
      cover,
      el('div', { class: 'dfb-song-body' }, [
        el('div', { class: 'dfb-song-head' }, [
          el('strong', { class: 'dfb-song-name', text: song.name || '未知曲目' }),
          el('span', { class: 'dfb-song-tags' }, tags)
        ]),
        el('div', { class: 'dfb-song-score', text: fmtInt(song.score) }),
        el('div', { class: 'dfb-song-meta', text: metaBits.join(' · ') }),
        el('div', { class: 'dfb-song-ratio' }, [
          el('span', { text: '战力 ' + show(song.ratioInt) }),
          el('em', { class: 'dfb-diff ' + diffClass, text: diffText })
        ])
      ])
    ]);
  }

  function songGrid(songs) {
    if (!songs || !songs.length) return emptyBox('没有符合条件的成绩');
    return el('div', { class: 'dfb-song-grid' }, songs.map(songCard));
  }

  function profileHero(profile) {
    var metaBits = ['ID ' + show(profile.userId)];
    if (profile.cityName) metaBits.push(profile.cityName);
    if (profile.teamName) metaBits.push('战队 ' + profile.teamName);
    if (profile.status === 'private') metaBits.push('该账号已设置隐私');

    return el('div', { class: 'dfb-hero' }, [
      thumb(profile.headimgUrl, profile.userName, (profile.userName || '?').slice(0, 1), 'dfb-hero-avatar'),
      el('div', { class: 'dfb-hero-info' }, [
        el('div', { class: 'dfb-hero-name', text: profile.userName || ('舞立方 ID ' + show(profile.userId)) }),
        el('div', { class: 'dfb-hero-meta', text: metaBits.join(' · ') })
      ]),
      el('div', { class: 'dfb-hero-ratio' }, [
        el('div', { class: 'dfb-hero-ratio-num', text: show(profile.lvRatio) }),
        el('div', { class: 'dfb-hero-ratio-label', text: '战力' })
      ])
    ]);
  }

  function ladderGradeName(grade) {
    var names = ['未定级', '一星', '二星', '三星', '星钻', '表神', '里神'];
    var g = parseInt(grade, 10);
    if (isNaN(g) || g < 0 || g >= names.length) return '未定级';
    return names[g];
  }

  function profileStats(profile, extra) {
    var items = [
      ['积分', fmtInt(profile.musicScore)],
      ['全连率', Number(profile.comboPercent || 0).toFixed(2) + '%'],
      ['全国排名', fmtInt(profile.rankNation)]
    ];
    if (extra) {
      items.push(['金币', fmtInt(extra.gold)]);
      items.push(['游玩次数', fmtInt(extra.playedTimes)]);
      items.push(['天梯段位', extra.ladderGrade === null || extra.ladderGrade === undefined ? '-' : ladderGradeName(extra.ladderGrade)]);
      items.push(['天梯分', extra.ladderScore === null || extra.ladderScore === undefined ? '-' : fmtInt(extra.ladderScore)]);
    } else {
      items.push(['地区', profile.cityName || '-']);
    }
    return statGrid(items);
  }

  function pager(page, totalPages, onGo) {
    var hasPrev = page > 1;
    var hasNext = totalPages > 0 && page < totalPages;
    return el('div', { class: 'dfb-pager' }, [
      el('button', {
        class: 'dfb-btn dfb-ghost', type: 'button', text: '‹ 上一页',
        disabled: hasPrev ? null : 'disabled',
        onclick: function () { if (hasPrev) onGo(page - 1); }
      }),
      el('span', { class: 'dfb-pager-info', text: '第 ' + page + ' / ' + (totalPages || 0) + ' 页' }),
      el('button', {
        class: 'dfb-btn dfb-ghost', type: 'button', text: '下一页 ›',
        disabled: hasNext ? null : 'disabled',
        onclick: function () { if (hasNext) onGo(page + 1); }
      })
    ]);
  }

  function clearTimers() {
    (state.timers || []).forEach(function (timer) { clearInterval(timer); });
    state.timers = [];
  }

  /* ================================================================== 状态 */
  var state = {
    info: null,
    timers: []
  };

  /* 侧边栏当前用户名：未登录固定“游客”，登录后换成舞立方昵称 */
  var currentName = '游客';

  function accountLabel() {
    return currentName || '游客';
  }

  function setDisplayName(name) {
    currentName = name || '游客';
    var node = $('#dfbUserName');
    if (node) node.textContent = currentName;
  }

  function renderUserState(info) {
    state.info = info;
    if (info) {
      LOGGED_IN = !!info.loggedIn;
    }
    var dot = $('#dfbUserDot');
    var hint = $('#dfbUserHint');
    var actions = $('#dfbUserActions');
    if (actions) actions.innerHTML = '';
    if (!dot || !hint) return;
    if (!info) {
      dot.dataset.state = 'off';
      setDisplayName('游客');
      hint.textContent = '正在读取登录状态…';
      return;
    }
    if (info.loggedIn) {
      dot.dataset.state = 'on';
      setDisplayName(info.userName || (info.userId ? ('舞立方用户 ' + info.userId) : '舞立方用户'));
      hint.textContent = '已登录 · 舞立方 ID ' + info.userId;
      /* 登录后才显示退出按钮 */
      if (actions) {
        actions.appendChild(el('button', {
          class: 'dfb-btn dfb-ghost dfb-btn-sm dfb-btn-block',
          type: 'button',
          text: '退出登录',
          onclick: doLogout
        }));
      }
    } else {
      dot.dataset.state = 'bad';
      setDisplayName('游客');
      hint.textContent = '请先登录再使用本站功能';
      if (actions && canUse('login')) {
        actions.appendChild(el('a', {
          class: 'dfb-btn dfb-btn-sm dfb-btn-block',
          href: PLUGIN_URL + '#/login',
          text: '账号登录'
        }));
      }
    }
  }

  /* 后台管理状态：由服务端注入，登录变化时通过 state 接口更新 */
  function adminState() {
    return CFG.admin || { eligible: false, unlocked: false };
  }

  function applyAdminState(admin) {
    if (!admin || typeof admin !== 'object') return false;
    var beforeEligible = !!(CFG.admin && CFG.admin.eligible);
    var beforeUnlocked = !!(CFG.admin && CFG.admin.unlocked);
    CFG.admin = admin;
    PERMS.admin = admin.eligible ? 1 : 0;
    return beforeEligible !== !!admin.eligible || beforeUnlocked !== !!admin.unlocked;
  }

  function refreshState(silent) {
    return request('state').then(function (info) {
      var wasLoggedIn = LOGGED_IN;
      var siteBefore = !!CFG.siteClosed;
      renderUserState(info);
      var authChanged = (wasLoggedIn !== LOGGED_IN);
      var adminChanged = applyAdminState(info && info.admin);
      CFG.siteClosed = !!(info && info.siteClosed);
      var siteChanged = siteBefore !== CFG.siteClosed;
      if (siteChanged && !CFG.siteClosed && currentRoute() === '/closed') {
        buildNav();
        location.hash = '#' + defaultHomeRoute();
        return info;
      }
      if (authChanged || adminChanged || siteChanged) {
        buildNav();
        if (authChanged) {
          if (!LOGGED_IN) {
            /* 刚退出 / 掉登录：回到登录页并重新渲染游客提示 */
            if (currentRoute() !== '/login') location.hash = '#/login';
            else renderRoute();
          } else if (currentRoute() === '/login') {
            /* 刚登录成功：手机回个人信息，桌面回首页 */
            location.hash = '#' + defaultHomeRoute();
          }
        } else if (currentRoute() === '/token-admin') {
          /* 正停在后台管理页时，重新渲染以显示解锁 / 锁定界面 */
          renderRoute();
        } else if (siteChanged) {
          renderRoute();
        }
      }
      return info;
    }).catch(function (error) {
      renderUserState(null);
      var hint = $('#dfbUserHint');
      if (hint) hint.textContent = '状态读取失败：' + error.message;
      if (!silent) toast(error.message, 'err');
      return null;
    });
  }

  /* 登录成功后刷新状态；如果登录的是管理员账号，提示后台管理入口已开启 */
  function afterLoginRefresh() {
    return refreshState(true).then(function (info) {
      if (info && info.admin && info.admin.eligible) {
        toast('检测到管理员账号：后台管理入口已开启，' +
          (info.admin.passwordSet === false ? '请先设置后台密码。' : '进入需输入后台密码。'), 'ok');
      }
      return info;
    });
  }

  /* ================================================================== 页面 */
  function mountHome(panel) {
    var groups = visibleNav();
    var tiles = [];
    groups.forEach(function (group) {
      group.items.forEach(function (item) {
        tiles.push(item);
      });
    });

    panel.appendChild(card('开始使用', '身份由浏览器自动分配，直接点选下面的功能即可。', [
      tiles.length
        ? el('div', { class: 'dfb-grid dfb-cols-3' }, tiles.map(function (item) {
            return el('button', {
              class: 'dfb-tile',
              type: 'button',
              onclick: function () { location.hash = '#' + item.route; }
            }, [
              el('span', { class: 'dfb-tile-ico', text: item.ico }),
              el('span', { class: 'dfb-tile-body' }, [
                el('strong', { text: item.label }),
                el('span', { text: TILE_DESC[item.route] || '' })
              ])
            ]);
          }))
        : emptyBox('当前站点没有开放可用功能。')
    ]));

    var limited = Object.keys(PERMS).filter(function (key) { return !PERMS[key]; });
    panel.appendChild(card('使用提示', null, [
      el('div', { class: 'dfb-alert info', text:
        '· 身份自动分配并保存在浏览器 Cookie 中，无需注册，也看不到他人的登录凭证。\n' +
        '· 所有数据由本站直接向舞立方接口拉取，直接在页面上渲染，不生成图片。\n' +
        '· 网页版不包含「加好友」相关功能。\n' +
        (limited.length ? '· 当前未开放：' + limited.map(featureName).join('、') + '。' : '· 你当前可以使用本站的全部功能。')
      })
    ]));
  }

  /* ------------------------------------------------------------ 账号登录（扫码 + 手机号 tab 整合） */
  function mountLogin(panel) {
    if (!LOGGED_IN) {
      panel.appendChild(alertBox('warn', '请先登录再使用本站功能'));
      panel.appendChild(spacer(12));
    }
    var modes = [
      { key: 'qr', label: '微信扫码', render: mountLoginQrContent },
      { key: 'phone', label: '手机号', render: mountLoginPhoneContent }
    ];
    var active = modes[0];
    var content = el('div');
    var tabs = el('div', { class: 'dfb-tabs' });

    function renderTabs() {
      tabs.innerHTML = '';
      modes.forEach(function (mode) {
        tabs.appendChild(el('button', {
          class: 'dfb-tab' + (active.key === mode.key ? ' active' : ''),
          type: 'button',
          text: mode.label,
          onclick: function () { active = mode; renderTabs(); content.innerHTML = ''; mode.render(content); }
        }));
      });
    }

    panel.appendChild(card('账号登录', '微信扫码或手机号验证码，二选一即可绑定舞立方账号。', [
      tabs, spacer(14), content
    ]));
    renderTabs();
    active.render(content);
  }

  function mountLoginQrContent(content) {
    content.innerHTML = '';
    var qrArea = el('div');
    var startBtn = el('button', {
      class: 'dfb-btn',
      type: 'button',
      text: '获取登录二维码',
      onclick: function () { startQrLogin(qrArea, startBtn); }
    });
    content.appendChild(el('div', { class: 'dfb-actions', style: 'margin-bottom:14px' }, [startBtn]));
    content.appendChild(qrArea);
  }

  function startQrLogin(area, button) {
    clearTimers();
    area.innerHTML = '';
    button.disabled = true;
    return withLoading(function () {
      return request('login.qrcode.start', { method: 'POST', body: {} });
    }, '正在获取登录二维码…').then(function (data) {
      var statusNode = el('div', { class: 'dfb-qr-status', text: '等待扫码中…' });
      area.appendChild(el('div', { class: 'dfb-qr' }, [
        el('img', { src: data.qr, alt: '登录二维码' }),
        el('p', { class: 'dfb-meta', text: data.tip || '' }),
        statusNode
      ]));

      var timer = setInterval(function () {
        request('login.qrcode.poll', { params: { ticket: data.ticket } }).then(function (res) {
          if (res.status === 'waiting') return;
          clearInterval(timer);
          button.disabled = false;
          statusNode.textContent = res.message || '登录结束';
          statusNode.className = 'dfb-qr-status ' + (res.status === 'success' ? 'ok' : 'err');
          if (res.status === 'success') {
            toast(res.message, 'ok');
            afterLoginRefresh();
          } else {
            toast(res.message || '登录未完成', 'err');
          }
        }).catch(function (error) {
          clearInterval(timer);
          button.disabled = false;
          statusNode.className = 'dfb-qr-status err';
          statusNode.textContent = error.message;
        });
      }, 2500);
      state.timers.push(timer);
    }).catch(function (error) {
      area.appendChild(alertBox('err', error.message));
      toast(error.message, 'err');
      button.disabled = false;
    });
  }

  /* ---------------------------------------------------------- 手机号登录 */
  function mountLoginPhoneContent(content) {
    content.innerHTML = '';
    var stepsBar = el('div', { class: 'dfb-steps' });
    var body = el('div');
    var phoneInput = el('input', { type: 'text', placeholder: '例如 13800000000', autocomplete: 'off' });
    var state = { phone: '', ticket: '', graph: '', tip: '' };

    function renderSteps(active) {
      stepsBar.innerHTML = '';
      ['获取图形码', '校验图形码', '短信登录'].forEach(function (label, index) {
        var step = index + 1;
        var cls = 'dfb-step';
        if (step === active) cls += ' active';
        else if (step < active) cls += ' done';
        stepsBar.appendChild(el('div', { class: cls }, [
          el('span', { class: 'dfb-step-n', text: String(step) }),
          label
        ]));
      });
    }

    /* 统一走这里取图形验证码：首次进入与「换一张」都复用 */
    function loadGraph(phone, isRetry) {
      if (!phone) { toast('请填写手机号', 'warn'); return; }
      withLoading(function () {
        return request('login.phone.start', { method: 'POST', body: { phone: phone } });
      }, '正在获取图形验证码…').then(function (data) {
        state.phone = data.phone || phone;
        state.ticket = data.ticket;
        state.graph = data.graph;
        state.tip = data.tip || '';
        stepTwo();
        if (isRetry) toast('已换一张验证码', 'ok');
      }).catch(function (error) {
        toast(error.message, 'err');
        if (!isRetry) {
          body.appendChild(alertBox('err', error.message));
        }
      });
    }

    function stepOne() {
      renderSteps(1);
      body.innerHTML = '';
      body.appendChild(field('手机号', phoneInput));
      body.appendChild(el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '获取图形验证码',
          onclick: function () {
            loadGraph(phoneInput.value.trim(), false);
          }
        })
      ]));
    }

    function stepTwo() {
      renderSteps(2);
      body.innerHTML = '';

      var img = el('img', { class: 'dfb-graph', src: state.graph, alt: '图形验证码' });
      var failed = el('p', { class: 'dfb-meta dfb-graph-fail', text: '验证码图片没能加载出来，点「换一张」重试。' });
      failed.hidden = true;
      img.addEventListener('error', function () {
        img.hidden = true;
        failed.hidden = false;
      });

      var codeInput = el('input', { type: 'text', placeholder: '填写图片中的识别码', autocomplete: 'off' });

      body.appendChild(el('div', { class: 'dfb-graph-box' }, [img, failed]));
      body.appendChild(el('p', { class: 'dfb-meta', text: state.tip || ('手机号：' + state.phone) }));
      body.appendChild(spacer(12));
      body.appendChild(field('图形验证码', codeInput));
      body.appendChild(el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '发送短信验证码',
          onclick: function (event) {
            var code = codeInput.value.trim();
            if (!code) { toast('请填写图形验证码', 'warn'); return; }
            event.target.disabled = true;
            withLoading(function () {
              return request('login.phone.sms', { method: 'POST', body: { ticket: state.ticket, graphCode: code } });
            }, '正在发送短信验证码…').then(function (data) {
              toast(data.message || '短信已发送', 'ok');
              stepThree(state.ticket, state.phone);
            }).catch(function (error) {
              toast(error.message, 'err');
              event.target.disabled = false;
            });
          }
        }),
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '换一张',
          onclick: function () {
            loadGraph(phoneInput.value.trim() || state.phone, true);
          }
        }),
        el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '重新开始', onclick: stepOne })
      ]));
    }

    function stepThree(ticket, phone) {
      renderSteps(3);
      body.innerHTML = '';
      var smsInput = el('input', { type: 'text', placeholder: '填写收到的短信验证码', autocomplete: 'off' });
      body.appendChild(alertBox('ok', '验证码已发送至 ' + phone + '，请及时查收'));
      body.appendChild(spacer(12));
      body.appendChild(field('短信验证码', smsInput));
      body.appendChild(el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '完成登录',
          onclick: function (event) {
            var code = smsInput.value.trim();
            if (!code) { toast('请填写短信验证码', 'warn'); return; }
            event.target.disabled = true;
            withLoading(function () {
              return request('login.phone.verify', { method: 'POST', body: { ticket: ticket, smsCode: code } });
            }, '正在登录…').then(function (data) {
              toast(data.message || '登录成功', 'ok');
              body.innerHTML = '';
              body.appendChild(alertBox('ok', data.message || '登录成功'));
              afterLoginRefresh();
              renderSteps(4);
            }).catch(function (error) {
              toast(error.message, 'err');
              event.target.disabled = false;
            });
          }
        }),
        el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '重新开始', onclick: stepOne })
      ]));
    }

    stepOne();
    content.appendChild(stepsBar);
    content.appendChild(spacer(10));
    content.appendChild(body);
  }

  /* -------------------------------------------------------------- 退出登录 */
  /* 提取为独立函数：侧边栏「当前用户」下方的退出按钮、以及 /logout 页面都复用它 */
  function doLogout() {
    if (!window.confirm('确认退出账号 ' + accountLabel() + ' 吗？')) return;
    withLoading(function () {
      return request('logout', { method: 'POST', body: {} });
    }, '正在退出登录…').then(function (data) {
      toast(data.message || '已退出登录', 'ok');
      /* 退出成功即视为游客：先切回游客界面，避免刷新状态失败时仍显示功能 */
      LOGGED_IN = false;
      renderUserState({ loggedIn: false });
      buildNav();
      if (currentRoute() !== '/login') location.hash = '#/login';
      else renderRoute();
      refreshState(true);
    }).catch(function (error) {
      toast(error.message, 'err');
    });
  }

  function mountSiteClosed(panel) {
    panel.appendChild(card('站点维护中', '网站暂时关闭，仅保留公告、登录与退出。', [
      el('div', { class: 'dfb-alert warn', text:
        '非管理员账号暂时不能使用战力分析、分数列表、战队、查询等功能。\n' +
        '维护完成后刷新页面即可恢复。' }),
      spacer(14),
      el('div', { class: 'dfb-actions' }, [
        LOGGED_IN
          ? el('button', { class: 'dfb-btn dfb-danger', type: 'button', text: '退出登录', onclick: doLogout })
          : el('a', { class: 'dfb-btn', href: PLUGIN_URL + '#/login', text: '账号登录' })
      ])
    ]));
  }

  function mountLogout(panel) {
    panel.appendChild(card('退出登录', '删除本账号保存的登录凭证。', [
      el('div', { class: 'dfb-alert warn', text: '退出后需要重新登录才能使用战力、成绩等需要账号的功能。' }),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn dfb-danger',
          type: 'button',
          text: '确认退出登录',
          onclick: doLogout
        })
      ])
    ]));
  }

  /* -------------------------------------------------------------- 个人信息 */
  function mountUserInfo(panel) {
    var targetInput = el('input', {
      type: 'text',
      placeholder: '留空则查看自己；也可填他人舞立方 ID',
      autocomplete: 'off'
    });
    var result = el('div');
    var currentData = null;
    var exportBusy = false;

    function exportImage() {
      if (!currentData || !currentData.profile) {
        toast('个人信息还没加载完成，请稍后再试', 'err');
        return;
      }
      if (exportBusy) return;
      exportBusy = true;
      var profile = currentData.profile;
      var target = currentData.isSelf ? '' : String(profile.userId || '');
      var sep = API_URL.indexOf('?') === -1 ? '?' : '&';
      var url = API_URL + sep
        + 'act=user.image&target=' + encodeURIComponent(target)
        + '&formhash=' + encodeURIComponent(CFG.formhash || '')
        + '&dfb_web_id=' + encodeURIComponent(CFG.webId || '');
      toast('正在生成个人信息图片…');
      fetch(url, { credentials: 'include' }).then(function (resp) {
        var type = resp.headers.get('content-type') || '';
        if (!resp.ok || type.indexOf('image/') !== 0) {
          return resp.text().then(function (bodyText) {
            var detail = '图片生成失败';
            try {
              var body = JSON.parse(bodyText);
              detail = body.detail || body.message || detail;
            } catch (e) {
              // 非 JSON 错误
            }
            throw new Error(detail);
          });
        }
        return resp.blob();
      }).then(function (blob) {
        var href = URL.createObjectURL(blob);
        var name = (profile.userName || ('ID-' + profile.userId)).replace(/[\\/:*?"<>|\s]+/g, '-');
        var link = document.createElement('a');
        link.href = href;
        link.download = '舞小枫-个人信息-' + name + '.png';
        link.style.display = 'none';
        document.body.appendChild(link);
        try {
          link.click();
          toast('个人信息图片已生成，请查看下载', 'ok');
        } catch (e) {
          window.open(href);
        }
        setTimeout(function () {
          if (link.parentNode) link.parentNode.removeChild(link);
          URL.revokeObjectURL(href);
        }, 1000);
      }).catch(function (error) {
        toast(error.message || '图片生成失败', 'err');
      }).then(function () {
        exportBusy = false;
      });
    }

    function load(target) {
      result.innerHTML = '';
      currentData = null;
      return withLoading(function () {
        return request('user.info', { params: { target: target || '' } });
      }, '正在读取个人信息…').then(function (data) {
        currentData = data;
        var profile = data.profile || {};
        result.appendChild(profileHero(profile));
        result.appendChild(spacer(12));
        if (profile.status === 'private') {
          result.appendChild(alertBox('warn', '该账号已设置隐私，只能看到公开信息（昵称 / 战力 / 地区）。'));
          result.appendChild(spacer(10));
        }
        result.appendChild(profileStats(profile, data.extra));
        return data;
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    panel.appendChild(card('个人信息', '「看看你的」对应这里的「查看他人」：填写他人舞立方 ID 即可。', [
      el('div', { class: 'dfb-field-row' }, [
        field('目标（可选）', targetInput)
      ]),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '读取个人信息',
          onclick: function () { load(targetInput.value.trim()); }
        }),
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '只看自己',
          onclick: function () { targetInput.value = ''; load(''); }
        }),
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '导出个人信息图片',
          onclick: exportImage
        })
      ]),
      spacer(14),
      result
    ]));

    load('');
  }

  /* -------------------------------------------------------------- 战力分析 */
  function mountRatio(panel) {
    var result = el('div');
    var modes = [
      { key: 'b15', act: 'ratio.b15', label: 'B15 / R15' },
      { key: 'b30', act: 'ratio.b30', label: 'B30' },
      { key: 'ap30', act: 'ratio.ap30', label: 'AP30' }
    ];
    var active = modes[0];
    var tabs = el('div', { class: 'dfb-actions' });
    var currentData = null;
    var exportBusy = false;

    function renderTabs() {
      tabs.innerHTML = '';
      modes.forEach(function (mode) {
        tabs.appendChild(el('button', {
          class: 'dfb-btn' + (active.key === mode.key ? '' : ' dfb-ghost'),
          type: 'button',
          text: mode.label,
          onclick: function () { active = mode; renderTabs(); run(); }
        }));
      });
    }

    function summaryCard(data) {
      var s = data.summary || {};
      var items = [
        ['当前战力', s.lvRatio],
        ['上次战力', s.lastRatio],
        [s.mainLabel || '本组战力', s.avgMain]
      ];
      if (s.subLabel) items.push([s.subLabel, s.avgSub]);
      if (s.avgAll !== null && s.avgAll !== undefined) items.push(['平均战力', s.avgAll]);

      var kids = [statGrid(items)];
      if (s.comment) {
        kids.push(spacer(10));
        kids.push(el('div', { class: 'dfb-quote', text: s.comment }));
      }
      return card(s.title || '战力分析', null, kids);
    }

    function exportImage() {
      if (!currentData) {
        toast('数据还没加载完成，请稍后再试', 'err');
        return;
      }
      if (exportBusy) {
        return;
      }
      exportBusy = true;
      var sep = API_URL.indexOf('?') === -1 ? '?' : '&';
      var url = API_URL + sep
        + 'act=ratio.image&mode=' + encodeURIComponent(active.key)
        + '&formhash=' + encodeURIComponent(CFG.formhash || '')
        + '&dfb_web_id=' + encodeURIComponent(CFG.webId || '');
      toast('正在生成模板图片…');
      fetch(url, { credentials: 'include' }).then(function (resp) {
        var type = resp.headers.get('content-type') || '';
        if (!resp.ok || type.indexOf('image/') !== 0) {
          return resp.text().then(function (bodyText) {
            var detail = '图片生成失败';
            try {
              var body = JSON.parse(bodyText);
              detail = body.detail || body.message || detail;
            } catch (e) {
              // 非 JSON 错误
            }
            throw new Error(detail);
          });
        }
        return resp.blob();
      }).then(function (blob) {
        var href = URL.createObjectURL(blob);
        var label = active.label.replace(/[\\/:*?"<>|\s]+/g, '-');
        var link = document.createElement('a');
        link.href = href;
        link.download = '舞小枫-' + label + '-战力分析.png';
        link.style.display = 'none';
        document.body.appendChild(link);
        try {
          link.click();
          toast('模板图片已生成，请查看下载', 'ok');
        } catch (e) {
          window.open(href);
        }
        setTimeout(function () {
          if (link.parentNode) link.parentNode.removeChild(link);
          URL.revokeObjectURL(href);
        }, 1000);
      }).catch(function (error) {
        toast(error.message || '图片生成失败', 'err');
      }).then(function () {
        exportBusy = false;
      });
    }

    function run() {
      result.innerHTML = '';
      currentData = null;
      return withLoading(function () {
        return request(active.act);
      }, '正在统计 ' + active.label + ' 数据…').then(function (data) {
        currentData = data;
        result.appendChild(summaryCard(data));
        result.appendChild(spacer(14));

        if (data.mode === 'b15') {
          result.appendChild(sectionTitle('B15 榜单战力前 15', '共 ' + (data.songs || []).length + ' 首'));
          result.appendChild(songGrid(data.songs));
          result.appendChild(spacer(16));
          result.appendChild(sectionTitle('R15 最近游玩 15 首', '共 ' + (data.songsSub || []).length + ' 首'));
          result.appendChild(songGrid(data.songsSub));
        } else {
          var title = data.mode === 'b30' ? 'B30 战力前 30 首' : 'AP30 满分成绩前 30 首';
          result.appendChild(sectionTitle(title, '共 ' + (data.songs || []).length + ' 首'));
          result.appendChild(songGrid(data.songs));
        }
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    renderTabs();
    panel.appendChild(card('战力分析', '并发拉取舞立方接口；45 秒内切换 B15 / B30 / AP30 会复用缓存。', [
      tabs,
      spacer(10),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '导出当前类型图片',
          onclick: exportImage
        }),
        el('span', { class: 'dfb-section-note', text: '切换类型后可分别导出三张图片' })
      ]),
      spacer(14),
      result
    ]));

    run();
  }

  /* -------------------------------------------------------------- 分数列表 */
  function mountLevelScores(panel) {
    var levelSelect = el('select');
    var i;
    for (i = 1; i <= 20; i += 1) {
      levelSelect.appendChild(el('option', { value: String(i), text: '等级 ' + i }));
    }
    var pageInput = el('input', { type: 'number', min: '1', max: '100', value: '1' });
    var result = el('div');
    var currentData = null;
    var exportBusy = false;

    function exportImage() {
      if (!currentData) {
        toast('数据还没加载完成，请稍后再试', 'err');
        return;
      }
      if (exportBusy) return;
      exportBusy = true;
      var level = currentData.level;
      var page = currentData.page;
      var sep = API_URL.indexOf('?') === -1 ? '?' : '&';
      var url = API_URL + sep
        + 'act=level.image&level=' + encodeURIComponent(level)
        + '&page=' + encodeURIComponent(page)
        + '&formhash=' + encodeURIComponent(CFG.formhash || '')
        + '&dfb_web_id=' + encodeURIComponent(CFG.webId || '');
      toast('正在生成分数列表图片…');
      fetch(url, { credentials: 'include' }).then(function (resp) {
        var type = resp.headers.get('content-type') || '';
        if (!resp.ok || type.indexOf('image/') !== 0) {
          return resp.text().then(function (bodyText) {
            var detail = '图片生成失败';
            try {
              var body = JSON.parse(bodyText);
              detail = body.detail || body.message || detail;
            } catch (e) {
              // 非 JSON 错误
            }
            throw new Error(detail);
          });
        }
        return resp.blob();
      }).then(function (blob) {
        var href = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = href;
        link.download = '舞小枫-等级' + level + '-分数列表-第' + page + '页.png';
        link.style.display = 'none';
        document.body.appendChild(link);
        try {
          link.click();
          toast('分数列表图片已生成，请查看下载', 'ok');
        } catch (e) {
          window.open(href);
        }
        setTimeout(function () {
          if (link.parentNode) link.parentNode.removeChild(link);
          URL.revokeObjectURL(href);
        }, 1000);
      }).catch(function (error) {
        toast(error.message || '图片生成失败', 'err');
      }).then(function () {
        exportBusy = false;
      });
    }

    function load(page) {
      var level = levelSelect.value;
      var target = page || parseInt(pageInput.value, 10) || 1;
      pageInput.value = String(target);
      result.innerHTML = '';
      currentData = null;
      return withLoading(function () {
        return request('level.scores', { params: { level: level, page: target } });
      }, '正在整理 ' + level + ' 级成绩…').then(function (data) {
        currentData = data;
        if (!data.total) {
          result.appendChild(emptyBox('这个等级还没有官方谱面成绩'));
          return;
        }
        result.appendChild(statGrid([
          ['等级', data.level],
          ['成绩数', data.total],
          ['本页平均战力', data.avg]
        ]));
        result.appendChild(spacer(12));
        result.appendChild(songGrid(data.songs));
        result.appendChild(spacer(14));
        result.appendChild(pager(data.page, data.totalPages, function (next) { load(next); }));
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    panel.appendChild(card('分数列表', '按等级查看成绩，每页 30 首，按准确率排序。', [
      el('div', { class: 'dfb-field-row' }, [
        field('歌曲等级', levelSelect),
        field('页码', pageInput)
      ]),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '查看成绩',
          onclick: function () { load(1); }
        }),
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '导出本页图片',
          onclick: exportImage
        })
      ]),
      spacer(14),
      result
    ]));

    load(1);
  }

  /* -------------------------------------------------------------- 我的战队 */
  function mountTeam(panel) {
    var result = el('div');
    var currentData = null;
    var currentSort = 'default';
    var currentOrder = 'desc';
    var sortSelect = el('select');
    var sortOptions = [
      ['default', '默认排序'],
      ['month', '月贡献'],
      ['total', '总贡献'],
      ['join', '加入时间'],
      ['ratio', '战力'],
      ['gold', '金币']
    ];
    sortOptions.forEach(function (item) {
      sortSelect.appendChild(el('option', { value: item[0], text: item[1] }));
    });
    var orderBtn = el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '默认顺序', disabled: 'disabled' });

    function updateOrderButton() {
      if (currentSort === 'default') {
        orderBtn.textContent = '默认顺序';
        orderBtn.disabled = true;
        return;
      }
      orderBtn.disabled = false;
      orderBtn.textContent = currentOrder === 'asc' ? '升序 ↑' : '降序 ↓';
    }

    function teamMember(member) {
      var roleClass = Number(member.memberType) === 1 ? ' leader' : (Number(member.memberType) === 2 ? ' deputy' : '');
      return el('div', { class: 'dfb-team-member' }, [
        thumb(member.headimgUrl, member.userName, (member.userName || '?').slice(0, 1), 'dfb-team-avatar'),
        el('div', { class: 'dfb-team-member-main' }, [
          el('div', { class: 'dfb-team-member-head' }, [
            el('strong', { class: 'dfb-team-member-name', text: member.userName || ('用户 ' + member.userId) }),
            el('span', { class: 'dfb-team-role' + roleClass, text: member.memberTypeText || '成员' })
          ]),
          el('div', {
            class: 'dfb-team-member-meta',
            text: 'ID ' + show(member.userId) + ' · 战力 ' + fmtInt(member.lvRatio) +
              ' · 总贡献 ' + fmtInt(member.pointTotal) + ' · 本月 ' + fmtInt(member.pointMonth)
          }),
          el('div', {
            class: 'dfb-team-member-meta',
            text: '金币 ' + fmtInt(member.gold) + ' · 加入 ' + (member.addTime || '-') +
              ' · 活跃 ' + (member.lastAccessText || '-')
          })
        ])
      ]);
    }

    sortSelect.addEventListener('change', function () {
      currentSort = sortSelect.value || 'default';
      if (currentSort === 'default') currentOrder = 'desc';
      updateOrderButton();
      load(1);
    });
    orderBtn.addEventListener('click', function () {
      if (currentSort === 'default') return;
      currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
      updateOrderButton();
      load(1);
    });
    updateOrderButton();

    function load(page) {
      result.innerHTML = '';
      result.appendChild(emptyBox('正在读取战队信息…'));
      currentData = null;
      return withLoading(function () {
        return request('team.detail', {
          params: { page: page || 1, sort: currentSort, order: currentOrder }
        });
      }, '正在读取战队信息…').then(function (data) {
        result.innerHTML = '';
        currentData = data;
        if (!data.hasTeam || !data.team) {
          result.appendChild(emptyBox(data.message || '当前账号还没有加入战队'));
          return;
        }
        var team = data.team;
        var me = data.me;

        result.appendChild(el('div', { class: 'dfb-team-hero' }, [
          thumb(team.logo, team.name, (team.name || '战').slice(0, 1), 'dfb-team-logo'),
          el('div', { class: 'dfb-team-main' }, [
            el('div', { class: 'dfb-team-name', text: team.name || ('战队 #' + team.id) }),
            el('div', {
              class: 'dfb-team-meta',
              text: '战队 ID ' + show(team.id) + ' · 等级 ' + show(team.level) +
                ' · 排名 ' + show(team.rank) + ' · 创建 ' + (team.addTime || '-')
            }),
            el('div', {
              class: 'dfb-team-meta',
              text: '成员 ' + show(team.currentMembers) + ' / ' + show(team.maxMembers)
            })
          ]),
          el('span', {
            class: 'dfb-team-badge' + (team.isBelong ? ' joined' : ''),
            text: team.isRequesting ? '申请中' : (team.isBelong ? '已加入' : '未加入')
          })
        ]));

        result.appendChild(spacer(12));
        var teamStats = statGrid([
          ['战队等级', team.level],
          ['战队排名', team.rank],
          ['战队积分', fmtInt(team.point)],
          ['战队金币', fmtInt(team.gold)],
          ['成员', show(team.currentMembers) + ' / ' + show(team.maxMembers)]
        ]);
        teamStats.classList.add('dfb-team-stats');
        result.appendChild(teamStats);

        if (team.intro) {
          result.appendChild(spacer(12));
          result.appendChild(el('div', { class: 'dfb-team-intro', text: team.intro }));
        }

        if (me) {
          result.appendChild(spacer(12));
          result.appendChild(el('div', { class: 'dfb-team-me' }, [
            el('span', { class: 'dfb-team-me-role', text: me.memberTypeText || '成员' }),
            el('span', {
              text: '我的总贡献 ' + fmtInt(me.pointTotal) +
                ' · 本月 ' + fmtInt(me.pointMonth) +
                ' · 战力 ' + fmtInt(me.lvRatio)
            })
          ]));
        }

        result.appendChild(spacer(16));
        result.appendChild(sectionTitle(
          '成员列表',
          '第 ' + data.page + ' / ' + (data.memberPages || 1) + ' 页 · 共 ' + show(team.currentMembers) + ' 人'
        ));
        if (!data.members.length) {
          result.appendChild(emptyBox('这一页没有成员'));
        } else {
          var list = el('div', { class: 'dfb-team-members' });
          data.members.forEach(function (member) {
            list.appendChild(teamMember(member));
          });
          result.appendChild(list);
        }
        result.appendChild(spacer(14));
        result.appendChild(pager(data.page, data.memberPages, function (next) {
          load(next);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }));
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    panel.appendChild(card('我的战队', '读取 mydc 个人信息中的 TeamID，再获取战队资料与成员列表。', [
      el('div', { class: 'dfb-field-row' }, [
        field('成员排序', sortSelect),
        field('顺序', orderBtn)
      ]),
      spacer(14),
      result
    ]));
    load(1);
  }

  /* -------------------------------------------------------------- 留言板 */
  function mountMessages(panel) {
    var result = el('div');
    var input = el('textarea', { placeholder: '写下你的建议、反馈或想说的话…（最多 500 字，可换行）' });
    var submitBtn = el('button', { class: 'dfb-btn', type: 'button', text: '发布留言' });
    var busy = false;

    function messageNode(item) {
      var head = el('div', { class: 'dfb-message-head' }, [
        el('strong', { text: item.userName || ('舞立方用户 ' + item.userId) }),
        el('span', { class: 'dfb-message-time', text: item.createdAt ? new Date(item.createdAt * 1000).toLocaleString() : '' })
      ]);
      if (isAdminUser() && CFG.admin && CFG.admin.unlocked) {
        head.appendChild(el('button', {
          class: 'dfb-btn dfb-ghost dfb-btn-sm',
          type: 'button',
          text: '删除',
          onclick: function () {
            if (!window.confirm('确认删除这条留言吗？')) return;
            withLoading(function () {
              return request('admin.message.delete', { method: 'POST', body: { id: item.id } });
            }, '正在删除留言…').then(function () {
              toast('留言已删除', 'ok');
              load();
            }).catch(function (error) {
              toast(error.message, 'err');
            });
          }
        }));
      }
      return el('div', { class: 'dfb-message' }, [
        head,
        el('div', { class: 'dfb-message-content', text: item.content })
      ]);
    }

    function load() {
      result.innerHTML = '';
      return withLoading(function () {
        return request('message.list');
      }, '正在读取留言…').then(function (data) {
        var messages = data.messages || [];
        result.appendChild(el('div', { class: 'dfb-meta', text: '共 ' + (data.total || messages.length) + ' 条留言' }));
        result.appendChild(spacer(10));
        if (!messages.length) {
          result.appendChild(emptyBox('还没有留言，来写下第一条建议吧。'));
          return;
        }
        var list = el('div', { class: 'dfb-messages' });
        messages.forEach(function (item) { list.appendChild(messageNode(item)); });
        result.appendChild(list);
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
      });
    }

    function submit() {
      if (busy) return;
      var content = input.value.trim();
      if (!content) { toast('留言内容不能为空', 'warn'); return; }
      if (content.length > 500) { toast('留言最多 500 个字符', 'warn'); return; }
      busy = true;
      submitBtn.disabled = true;
      withLoading(function () {
        return request('message.post', { method: 'POST', body: { content: content } });
      }, '正在发布留言…').then(function () {
        input.value = '';
        toast('留言已发布', 'ok');
        load();
      }).catch(function (error) {
        toast(error.message, 'err');
      }).then(function () {
        busy = false;
        submitBtn.disabled = false;
      });
    }
    submitBtn.addEventListener('click', submit);

    panel.appendChild(card('留言板', '登录用户都可以在这里发言、提建议；请文明交流。', [
      field('留言内容', input),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [submitBtn]),
      spacer(16),
      result
    ]));
    load();
  }

  /* -------------------------------------------------------------- 更新日志 */
  function mountChangelog(panel) {
    var result = el('div');

    function load() {
      result.innerHTML = '';
      return withLoading(function () {
        return request('changelog.list');
      }, '正在读取更新日志…').then(function (data) {
        var entries = data.entries || [];
        if (!entries.length) {
          result.appendChild(emptyBox('还没有更新记录。'));
          return;
        }
        var timeline = el('div', { class: 'dfb-changelog' });
        entries.forEach(function (entry) {
          var content = el('div', { class: 'dfb-changelog-body' }, [
            el('div', { class: 'dfb-changelog-head' }, [
              el('strong', { text: entry.title || '版本更新' }),
              el('span', { class: 'dfb-changelog-time', text: [entry.version, entry.date].filter(Boolean).join(' · ') })
            ])
          ]);
          if (entry.items && entry.items.length) {
            var ul = el('ul', { class: 'dfb-changelog-list' });
            entry.items.forEach(function (line) {
              ul.appendChild(el('li', { text: line }));
            });
            content.appendChild(ul);
          }
          timeline.appendChild(el('div', { class: 'dfb-changelog-item' }, [
            el('span', { class: 'dfb-changelog-dot' }),
            content
          ]));
        });
        result.appendChild(timeline);
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    panel.appendChild(card('更新日志', '这里记录舞小枫 Web / App 的功能更新。', [result]));
    load();
  }

  /* -------------------------------------------------------------- 数据统计 */
  function mountReplyItem(panel) {
    var result = el('div');

    function load() {
      result.innerHTML = '';
      return withLoading(function () {
        return request('reply.item');
      }, '正在读取统计数据…').then(function (data) {
        var fields = data.fields || [];
        result.appendChild(statGrid(fields.map(function (item) {
          return [item.label, item.value];
        })));
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    panel.appendChild(card('个人数据统计', '对应 QQ 端的「myri」，汇总胜率、游玩次数、通过歌曲等数据。', [
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '刷新统计',
          onclick: load
        })
      ]),
      spacer(14),
      result
    ]));

    load();
  }

  /* -------------------------------------------------------------- 查找机台 */
  function mountMachine(panel) {
    var regionInput = el('input', { type: 'text', placeholder: '例如：广州、上海、北京', autocomplete: 'off' });
    var result = el('div');
    var photosVisible = true;
    try { photosVisible = window.localStorage.getItem('dfb_machine_photos') !== '0'; } catch (e) {}
    function excludeAction(item, direct) {
      var reason = window.prompt(direct ? '管理员直接排除，请填写理由（可留空）：' : '请填写排除理由（例如：已撤机 / 已倒闭）：', '');
      if (reason === null) return;
      reason = String(reason || '').trim();
      if (!direct && !reason) { toast('请填写排除理由', 'warn'); return; }
      var act = direct ? 'admin.machine.exclude.set' : 'machine.exclude.request';
      var body = {
        terminalId: item.terminalId,
        placeName: item.placeName || '',
        address: item.address || '',
        reason: reason
      };
      if (direct) body.excluded = true;
      withLoading(function () {
        return request(act, { method: 'POST', body: body });
      }, direct ? '正在直接排除…' : '正在提交排除申请…').then(function () {
        toast(direct ? '场地已直接排除' : '排除申请已提交，等待管理员审核', 'ok');
        search(lastRegion);
      }).catch(function (error) { toast(error.message, 'err'); });
    }

    function renderExcluded(data) {
      var list = data.excluded || [];
      if (!list.length) return null;
      var body = el('div', { class: 'dfb-excluded-list', hidden: true });
      list.forEach(function (item) {
        var row = el('div', { class: 'dfb-excluded-item' }, [
          el('div', { class: 'dfb-excluded-main' }, [
            el('strong', { text: item.placeName || '未命名店铺' }),
            el('span', { class: 'dfb-meta', text: item.address || '地址未知' }),
            el('span', { class: 'dfb-excluded-reason', text: '排除理由：' + (item.exclusionReason || '-') })
          ])
        ]);
        if (isAdminUser() && CFG.admin && CFG.admin.unlocked) {
          row.appendChild(el('button', {
            class: 'dfb-btn dfb-ghost dfb-btn-sm',
            type: 'button',
            text: '恢复场地',
            onclick: function () {
              if (!window.confirm('确认恢复这个场地到正常列表吗？')) return;
              withLoading(function () {
                return request('admin.machine.exclude.set', {
                  method: 'POST',
                  body: { terminalId: item.terminalId, excluded: false }
                });
              }, '正在恢复场地…').then(function () {
                toast('场地已恢复', 'ok');
                search(lastRegion);
              }).catch(function (error) { toast(error.message, 'err'); });
            }
          }));
        }
        body.appendChild(row);
      });
      var toggle = el('button', {
        class: 'dfb-btn dfb-ghost dfb-btn-sm',
        type: 'button',
        text: '展开排除列表（' + list.length + '）',
        onclick: function () {
          body.hidden = !body.hidden;
          toggle.textContent = (body.hidden ? '展开' : '收起') + '排除列表（' + list.length + '）';
        }
      });
      return card('排除列表（' + list.length + '）', '已确认撤机或倒闭的场地，默认折叠。', [toggle, spacer(8), body]);
    }

    function loadAdminPending() {
      if (!isAdminUser() || !CFG.admin || !CFG.admin.unlocked) return;
      var holder = el('div');
      result.appendChild(holder);
      request('admin.machine.exclusions').then(function (data) {
        var pending = data.pending || [];
        if (!pending.length) return;
        var body = el('div');
        pending.forEach(function (item) {
          var row = el('div', { class: 'dfb-exclusion-request' }, [
            el('div', { class: 'dfb-exclusion-request-main' }, [
              el('strong', { text: item.placeName || item.terminalId }),
              el('span', { class: 'dfb-meta', text: item.reason || '未填写理由' }),
              el('span', { class: 'dfb-meta', text: (item.userName || '用户') + ' · ' + (item.createdAt ? new Date(item.createdAt * 1000).toLocaleString() : '') })
            ]),
            el('div', { class: 'dfb-actions' }, [
              el('button', {
                class: 'dfb-btn dfb-btn-sm',
                type: 'button',
                text: '通过',
                onclick: function () {
                  withLoading(function () {
                    return request('admin.machine.exclude.review', { method: 'POST', body: { terminalId: item.terminalId, action: 'approve' } });
                  }, '正在通过申请…').then(function () { toast('已通过排除', 'ok'); search(lastRegion); }).catch(function (error) { toast(error.message, 'err'); });
                }
              }),
              el('button', {
                class: 'dfb-btn dfb-ghost dfb-btn-sm',
                type: 'button',
                text: '驳回',
                onclick: function () {
                  withLoading(function () {
                    return request('admin.machine.exclude.review', { method: 'POST', body: { terminalId: item.terminalId, action: 'reject' } });
                  }, '正在驳回申请…').then(function () { toast('已驳回申请', 'ok'); search(lastRegion); }).catch(function (error) { toast(error.message, 'err'); });
                }
              })
            ])
          ]);
          body.appendChild(row);
        });
        holder.appendChild(card('场地排除审核（' + pending.length + '）', '普通用户提交的排除申请，通过后会移入排除列表。', [body]));
      }).catch(function () {});
    }

    function search(region) {
      if (!region) { toast('请填写地区名', 'warn'); return; }
      lastRegion = region;
      result.innerHTML = '';
      withLoading(function () {
        return request('machine.list', { params: { region: region } });
      }, '正在查找附近的机台…').then(function (data) {
        result.appendChild(card('「' + data.region + '」的机台列表',
          '共 ' + data.total + ' 台，其中在线 ' + data.online + ' 台', [
          data.list && data.list.length ? listResult(data.list, machineNode) : emptyBox('没有找到机台')
        ]));
        var excluded = renderExcluded(data);
        if (excluded) result.appendChild(excluded);
        loadAdminPending();
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    var lastRegion = '';

    function persistPhotos(value) {
      photosVisible = value;
      try { window.localStorage.setItem('dfb_machine_photos', value ? '1' : '0'); } catch (e) {}
      Array.prototype.forEach.call(result.querySelectorAll('.dfb-machine-gallery'), function (node) {
        node.hidden = !value;
      });
    }

    function imageGallery(item) {
      var images = item.images || [];
      if (!images.length) return null;
      var gallery = el('div', { class: 'dfb-machine-gallery', hidden: !photosVisible });
      images.slice(0, 2).forEach(function (src) {
        gallery.appendChild(el('img', {
          src: src,
          alt: (item.placeName || '场地') + ' 图片',
          loading: 'lazy',
          onclick: function () { window.open(src, '_blank'); }
        }));
      });
      return gallery;
    }

    function reviewPhotoList(urls) {
      if (!urls || !urls.length) return null;
      var row = el('div', { class: 'dfb-review-photos' });
      urls.forEach(function (src) {
        row.appendChild(el('img', {
          src: src,
          loading: 'lazy',
          onclick: function () { window.open(src, '_blank'); }
        }));
      });
      return row;
    }

    function reviewNode(item, detail) {
      var head = el('div', { class: 'dfb-review-head' }, [
        el('strong', { text: item.userName || ('舞立方用户 ' + item.userId) }),
        el('span', { class: 'dfb-review-stars', text: '★★★★★'.slice(0, item.rating || 0) + '☆☆☆☆☆'.slice(0, 5 - (item.rating || 0)) }),
        el('span', { class: 'dfb-review-time', text: item.createdAt ? new Date(item.createdAt * 1000).toLocaleString() : '' })
      ]);
      if (isAdminUser() && CFG.admin && CFG.admin.unlocked) {
        head.appendChild(el('button', {
          class: 'dfb-btn dfb-ghost dfb-btn-sm',
          type: 'button',
          text: '删除',
          onclick: function () {
            if (!window.confirm('确认删除这条场地评价吗？')) return;
            withLoading(function () {
              return request('admin.machine.review.delete', { method: 'POST', body: { id: item.id } });
            }, '正在删除评价…').then(function () {
              toast('评价已删除', 'ok');
              loadReviews(activeMachine, activeDetail);
            }).catch(function (error) { toast(error.message, 'err'); });
          }
        }));
      }
      var children = [head, el('div', { class: 'dfb-review-content', text: item.content })];
      var photos = reviewPhotoList(item.images);
      if (photos) children.push(photos);
      return el('div', { class: 'dfb-review' }, children);
    }

    function fileToDataURL(file) {
      return new Promise(function (resolve, reject) {
        if (!/^image\//.test(file.type || '')) {
          reject(new Error('请选择图片文件'));
          return;
        }
        if (file.size > 8 * 1024 * 1024) {
          reject(new Error('图片太大了，请选择 4MB 以内的照片'));
          return;
        }
        var objectUrl = URL.createObjectURL(file);
        var image = new Image();
        image.onload = function () {
          var max = 1600;
          var scale = Math.min(1, max / Math.max(image.width, image.height));
          var canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(image.width * scale));
          canvas.height = Math.max(1, Math.round(image.height * scale));
          var ctx = canvas.getContext('2d');
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
          URL.revokeObjectURL(objectUrl);
          resolve(canvas.toDataURL('image/jpeg', 0.82));
        };
        image.onerror = function () {
          URL.revokeObjectURL(objectUrl);
          reject(new Error('图片读取失败'));
        };
        image.src = objectUrl;
      });
    }

    function uploadPhoto(file, formPhotos) {
      return withLoading(function () {
        return fileToDataURL(file).then(function (data) {
          return request('machine.review.upload', { method: 'POST', body: { data: data } });
        });
      }, '正在上传照片…').then(function (data) {
        reviewImages.push(data.url);
        renderPhotoPreviews(formPhotos);
      }).catch(function (error) { toast(error.message, 'err'); });
    }

    function renderPhotoPreviews(container) {
      container.innerHTML = '';
      reviewImages.forEach(function (src, index) {
        var thumb = el('div', { class: 'dfb-review-photo-thumb' }, [
          el('img', { src: src, onclick: function () { window.open(src, '_blank'); } }),
          el('button', {
            class: 'dfb-review-photo-remove',
            type: 'button',
            text: '×',
            onclick: function () {
              reviewImages.splice(index, 1);
              renderPhotoPreviews(container);
            }
          })
        ]);
        container.appendChild(thumb);
      });
    }

    function loadReviews(machine, detail) {
      var item = machine.__machine;
      activeMachine = machine;
      activeDetail = detail;
      detail.innerHTML = '';
      detail.appendChild(el('div', { class: 'dfb-meta', text: '正在读取评价…' }));
      return request('machine.review.list', { params: { terminalId: item.terminalId } }).then(function (data) {
        detail.innerHTML = '';
        var items = data.items || [];
        detail.appendChild(el('div', { class: 'dfb-review-summary' }, [
          el('span', { text: '场地评价 ' + (data.avg ? '⭐ ' + data.avg : '暂无评分') }),
          el('span', { class: 'dfb-meta', text: '共 ' + (data.total || 0) + ' 条' })
        ]));
        if (!items.length) {
          detail.appendChild(el('div', { class: 'dfb-empty', text: '还没有评价，来写第一条吧。' }));
        } else {
          items.forEach(function (review) { detail.appendChild(reviewNode(review, detail)); });
        }

        var rating = el('select', { class: 'dfb-input' });
        [5, 4, 3, 2, 1].forEach(function (n) {
          rating.appendChild(el('option', { value: String(n), text: '★'.repeat(n) + '☆'.repeat(5 - n) + ' ' + n + ' 星' }));
        });
        var content = el('textarea', { class: 'dfb-textarea', placeholder: '写下你对这个场地的评价…（最多 300 字）' });
        var photoInput = el('input', { type: 'file', accept: 'image/*', multiple: true, style: 'display:none' });
        var photoButton = el('button', { class: 'dfb-btn dfb-ghost dfb-btn-sm', type: 'button', text: '添加照片' });
        var photoPreviews = el('div', { class: 'dfb-review-photo-previews' });
        photoButton.addEventListener('click', function () { photoInput.click(); });
        photoInput.addEventListener('change', function () {
          var files = Array.prototype.slice.call(photoInput.files || []).slice(0, 3 - reviewImages.length);
          files.reduce(function (p, file) {
            return p.then(function () { return uploadPhoto(file, photoPreviews); });
          }, Promise.resolve()).then(function () { photoInput.value = ''; });
        });

        var submit = el('button', { class: 'dfb-btn', type: 'button', text: '发布评价' });
        submit.addEventListener('click', function () {
          var text = content.value.trim();
          if (!text) { toast('评价内容不能为空', 'warn'); return; }
          if (text.length > 300) { toast('评价最多 300 个字符', 'warn'); return; }
          withLoading(function () {
            return request('machine.review.post', {
              method: 'POST',
              body: { terminalId: item.terminalId, rating: Number(rating.value), content: text, images: reviewImages.slice() }
            });
          }, '正在发布评价…').then(function () {
            toast('评价已发布', 'ok');
            item.reviewCount = (item.reviewCount || 0) + 1;
            reviewImages = [];
            loadReviews(machine, detail);
          }).catch(function (error) { toast(error.message, 'err'); });
        });

        detail.appendChild(el('div', { class: 'dfb-review-form' }, [
          el('div', { class: 'dfb-field' }, [el('label', { text: '评分' }), rating]),
          el('div', { class: 'dfb-field' }, [el('label', { text: '评价内容' }), content]),
          el('div', { class: 'dfb-actions' }, [photoButton, photoInput]),
          photoPreviews,
          el('div', { class: 'dfb-actions', style: 'margin-top:10px' }, [submit])
        ]));
      }).catch(function (error) {
        detail.innerHTML = '';
        detail.appendChild(alertBox('err', error.message));
      });
    }

    function machineNode(item) {
      var detail = el('div', { class: 'dfb-machine-detail', hidden: true });
      var toggle = el('button', {
        class: 'dfb-btn dfb-ghost dfb-btn-sm',
        type: 'button',
        text: '查看评价 / 写评价',
        onclick: function () {
          var opening = !!detail.hidden;
          detail.hidden = !opening;
          if (opening) {
            reviewImages = [];
            loadReviews(node, detail);
          }
        }
      });
      var node = el('div', { class: 'dfb-machine' }, [
        imageGallery(item),
        el('div', { class: 'dfb-machine-name' }, [
          item.show ? el('span', { class: 'dfb-badge show', text: '⭐ 秀' }) : null,
          item.placeName || '未命名店铺',
          el('span', { class: 'dfb-badge ' + (item.online ? 'on' : 'off'), text: item.online ? '🔵 在线' : '🔴 离线' })
        ]),
        el('div', { class: 'dfb-machine-addr', text: item.address || '地址未知' }),
        el('div', { class: 'dfb-machine-extra' }, [
          el('span', { text: item.reviewCount ? ('⭐ ' + item.reviewAvg + ' · ' + item.reviewCount + ' 条评价') : '暂无评价' }),
          item.provinceCity ? el('span', { text: item.provinceCity }) : null,
          item.exclusionStatus === 'pending'
            ? el('span', { class: 'dfb-badge exclusion', text: '排除待审核' })
            : (item.terminalId ? el('button', {
              class: 'dfb-btn dfb-ghost dfb-btn-sm',
              type: 'button',
              text: (isAdminUser() && CFG.admin && CFG.admin.unlocked) ? '直接排除' : '申请排除',
              onclick: function () {
                excludeAction(item, isAdminUser() && CFG.admin && CFG.admin.unlocked);
              }
            }) : null),
          toggle
        ]),
        detail
      ]);
      node.__machine = item;
      return node;
    }

    var photoSwitch = el('input', { type: 'checkbox', checked: photosVisible });
    photoSwitch.addEventListener('change', function () { persistPhotos(!!photoSwitch.checked); });

    panel.appendChild(card('查找舞立方机台', '按地区查询附近的机台，会用到高德地图与舞立方接口；每个场地都可以查看图片和评价。', [
      el('div', { class: 'dfb-field-row' }, [
        field('地区（最多 15 字）', regionInput)
      ]),
      el('label', { class: 'dfb-photo-switch' }, [photoSwitch, el('span', { text: '显示机台照片' })]),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '查找机台',
          onclick: function () {
            search(regionInput.value.trim());
          }
        })
      ]),
      spacer(14),
      result
    ]));
    loadAdminPending();
  }


  /* ---------------------------------------------------------------- 兑换码 */
  function mountGainCode(panel) {
    var textarea = el('textarea', {
      placeholder: '每行一个兑换码，或用空格分隔\n例如：\naBcD1234567890X\nZx9Y8w7V6u5T4s3R'
    });
    var result = el('div');

    panel.appendChild(card('兑换自制谱兑换码', '兑换码为 15 位字母或数字，成功一个即停止（与 QQ 端行为一致）。', [
      field('兑换码', textarea),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '开始兑换',
          onclick: function () {
            var codes = textarea.value.split(/[\s,，、]+/).filter(Boolean);
            if (!codes.length) { toast('请先填写兑换码', 'warn'); return; }
            result.innerHTML = '';
            withLoading(function () {
              return request('gain.code', { method: 'POST', body: { codes: codes } });
            }, '小枫正在努力兑换…').then(function (data) {
              result.appendChild(alertBox(data.success ? 'ok' : 'warn', data.message));
              result.appendChild(spacer(10));
              result.appendChild(card('兑换结果', null, [
                listResult(data.results, function (item) {
                  return el('div', { class: 'dfb-kv-row' }, [
                    el('span', { text: item.code }),
                    el('span', {
                      text: item.status,
                      style: item.ok ? 'color:var(--dfb-ok)' : 'color:var(--dfb-err)'
                    })
                  ]);
                })
              ]));
            }).catch(function (error) {
              result.appendChild(alertBox('err', error.message));
              toast(error.message, 'err');
            });
          }
        })
      ]),
      spacer(14),
      result
    ]));
  }

  /* ------------------------------------------------------------ Token 查看 */
  function mountToken(panel) {
    var result = el('div');

    function runToken(fn, loadingText) {
      result.innerHTML = '';
      withLoading(fn, loadingText || '正在读取 Token…').then(function (data) {
        result.appendChild(alertBox('ok', data.message || '读取成功'));
        result.appendChild(spacer(10));
        result.appendChild(card('Token 详情', null, [
          el('div', { class: 'dfb-kv' }, [
            ['舞立方 ID', data.userId],
            ['可用状态', data.available ? '可用' : '已失效'],
            ['令牌时长', data.days + ' 天'],
            ['记录时间', new Date(data.recTime).toLocaleString('zh-CN')]
          ].map(function (pair) {
            return el('div', { class: 'dfb-kv-row' }, [
              el('span', { text: pair[0] }),
              el('span', { text: show(pair[1]) })
            ]);
          })),
          spacer(12),
          el('pre', { class: 'dfb-mono', text: data.text })
        ]));
      }).catch(function (error) {
        result.appendChild(alertBox('err', error.message));
        toast(error.message, 'err');
      });
    }

    var tokenActions = [
      el('button', {
        class: 'dfb-btn',
        type: 'button',
        text: '查看当前 Token',
        onclick: function () { runToken(function () { return request('token.show'); }); }
      }),
      el('button', {
        class: 'dfb-btn dfb-ghost',
        type: 'button',
        text: '强制刷新',
        onclick: function () {
          runToken(function () {
            return request('token.refresh', { method: 'POST', body: {} });
          }, '正在强制刷新 Token…');
        }
      })
    ];
    /* 默认 Token 是站点级凭证，只有通过密码验证的后台管理员才能查看 */
    if (adminState().unlocked) {
      tokenActions.push(el('button', {
        class: 'dfb-btn dfb-ghost',
        type: 'button',
        text: '查看默认 Token',
        onclick: function () { runToken(function () { return request('token.default.show'); }); }
      }));
    }

    panel.appendChild(card('查看 / 刷新 Token', 'Token 拥有账号的全部控制权，请勿泄露给他人。', [
      el('div', { class: 'dfb-actions' }, tokenActions),
      spacer(14),
      result
    ]));
  }

  /* ------------------------------------------------------------ 后台管理 */
  function mountTokenAdmin(panel) {
    if (!canUse('admin')) {
      panel.appendChild(card('后台管理', null, [
        alertBox('warn', adminState().reason || '当前账号没有后台管理权限。'),
        spacer(12),
        el('div', { class: 'dfb-actions' }, [
          el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/login', text: '去登录管理员账号' }),
          el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/', text: '返回功能总览' })
        ])
      ]));
      return;
    }
    if (!adminState().unlocked) {
      mountAdminLock(panel);
      return;
    }
    mountAdminConsole(panel);
  }

  function adminMinutes(seconds) {
    var n = Math.max(0, parseInt(seconds, 10) || 0);
    if (n <= 0) return '0 分钟';
    if (n < 60) return n + ' 秒';
    return Math.ceil(n / 60) + ' 分钟';
  }

  /* 后台管理锁屏：首次设置密码，之后输入密码 */
  function mountAdminLock(panel) {
    var admin = adminState();
    var status = el('div');
    var feedback = el('div');
    var passwordInput = el('input', { type: 'password', placeholder: '请输入后台密码', autocomplete: 'current-password' });
    var confirmInput = el('input', { type: 'password', placeholder: '再次输入后台密码', autocomplete: 'new-password' });
    var passwordLabel = el('label', { text: '后台密码' });
    var confirmField = el('div', { class: 'dfb-field' }, [
      el('label', { text: '确认密码' }),
      confirmInput
    ]);
    var submitBtn = el('button', { class: 'dfb-btn', type: 'button', text: '验证并进入' });
    var setupMode = admin.passwordSet === false;

    function updateCardText() {
      var title = panel.querySelector('.dfb-card-title');
      var sub = panel.querySelector('.dfb-card-sub');
      if (title) title.textContent = setupMode ? '设置后台密码' : '后台管理验证';
      if (sub) sub.textContent = setupMode
        ? '首次使用后台管理，请先设置一个只有你知道的密码。'
        : '检测到管理员账号，请输入后台密码后进入。';
    }

    function updateStatus() {
      admin = adminState();
      setupMode = admin.passwordSet === false;
      status.innerHTML = '';
      status.appendChild(alertBox('info',
        '当前账号：舞立方 ID ' + show(admin.userId) + '\n' +
        '后台管理包含：Token 管理、客户端 ID 管理\n' +
        '通过密码后 ' + adminMinutes(admin.ttl) + '内有效，可随时点「锁定后台」退出。'));
      if (setupMode) {
        status.appendChild(spacer(10));
        status.appendChild(alertBox('warn',
          '首次进入后台必须设置密码。\n' +
          '密码会用带随机盐的哈希保存（bcrypt / PBKDF2），站点不保存明文；' +
          '请至少输入 ' + (admin.minLength || 8) + ' 个字符，并务必记住。\n' +
          '忘记密码只能删除 data/admin.php（或 admin_secret_file 指定的文件）后重新设置。'));
        submitBtn.textContent = '设置密码并进入';
        passwordInput.placeholder = '设置后台密码（至少 ' + (admin.minLength || 8) + ' 个字符）';
        passwordInput.setAttribute('autocomplete', 'new-password');
        passwordLabel.textContent = '设置后台密码';
        confirmField.hidden = false;
      } else {
        submitBtn.textContent = '验证并进入';
        passwordInput.placeholder = '请输入后台密码';
        passwordInput.setAttribute('autocomplete', 'current-password');
        passwordLabel.textContent = '后台密码';
        confirmField.hidden = true;
      }
      if (admin.lockedFor > 0) {
        status.appendChild(spacer(10));
        status.appendChild(alertBox('err',
          '密码尝试次数过多，请 ' + adminMinutes(admin.lockedFor) + '后再试。'));
      } else if (!setupMode && admin.attemptsLeft > 0) {
        status.appendChild(spacer(10));
        status.appendChild(el('p', { class: 'dfb-meta', text: '剩余尝试次数：' + admin.attemptsLeft + ' 次' }));
      }
      var disabled = admin.lockedFor > 0;
      passwordInput.disabled = disabled;
      confirmInput.disabled = disabled;
      submitBtn.disabled = disabled;
      updateCardText();
    }

    function submit() {
      var password = passwordInput.value;
      if (!password) { toast('请输入后台密码', 'warn'); return; }
      if (setupMode && password !== confirmInput.value) {
        toast('两次输入的密码不一致', 'warn');
        return;
      }
      var act = setupMode ? 'admin.password.setup' : 'admin.unlock';
      var body = setupMode
        ? { password: password, confirm: confirmInput.value }
        : { password: password };
      submitBtn.disabled = true;
      feedback.innerHTML = '';
      withLoading(function () {
        return request(act, { method: 'POST', body: body });
      }, setupMode ? '正在设置后台密码…' : '正在校验后台密码…').then(function (data) {
        toast(data.message || (setupMode ? '后台密码设置成功' : '密码验证通过'), 'ok');
        passwordInput.value = '';
        confirmInput.value = '';
        applyAdminState(data.admin);
        buildNav();
        renderRoute();
      }).catch(function (error) {
        submitBtn.disabled = false;
        feedback.innerHTML = '';
        feedback.appendChild(alertBox('err', error.message));
        if (error.data && error.data.admin) {
          applyAdminState(error.data.admin);
          updateStatus();
        }
      });
    }

    submitBtn.addEventListener('click', submit);
    passwordInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !setupMode) { e.preventDefault(); submit(); }
    });
    confirmInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submit(); }
    });

    panel.appendChild(card('后台管理验证', '检测到管理员账号，请输入后台密码后进入。', [
      status,
      spacer(14),
      el('div', { class: 'dfb-field' }, [passwordLabel, passwordInput]),
      confirmField,
      spacer(12),
      el('div', { class: 'dfb-actions' }, [submitBtn]),
      spacer(12),
      feedback
    ]));
    updateStatus();
  }

  /* 通过密码验证后的后台管理界面 */
  function mountAdminConsole(panel) {
    var admin = adminState();
    var accessInput = el('textarea', { placeholder: 'Access Token' });
    var refreshInput = el('textarea', { placeholder: 'Refresh Token' });
    var currentPwdInput = el('input', { type: 'password', placeholder: '当前后台密码', autocomplete: 'current-password' });
    var newPwdInput = el('input', { type: 'password', placeholder: '新密码', autocomplete: 'new-password' });
    var confirmPwdInput = el('input', { type: 'password', placeholder: '再次输入新密码', autocomplete: 'new-password' });
    var pwdResult = el('div');
    var idResult = el('div');
    var result = el('div');
    var annContent = el('textarea', { placeholder: '公告内容，支持换行；只按纯文本显示。' });
    var annCloseable = el('select');
    annCloseable.appendChild(el('option', { value: '0', text: '不可关闭（所有用户始终看到）' }));
    annCloseable.appendChild(el('option', { value: '1', text: '用户可关闭' }));
    var annStatus = el('div');
    var annResult = el('div');
    if (CFG.announcement) {
      annContent.value = CFG.announcement.content || '';
      annCloseable.value = CFG.announcement.closeable ? '1' : '0';
    }

    function refreshAnnouncementStatus() {
      annStatus.innerHTML = '';
      if (!ANNOUNCEMENT) {
        annStatus.appendChild(alertBox('info', '当前没有公告。'));
        return;
      }
      annStatus.appendChild(alertBox('ok',
        '当前公告：' + (ANNOUNCEMENT.closeable ? '用户可关闭' : '不可关闭') +
        (ANNOUNCEMENT.updated ? ' · ' + new Date(Number(ANNOUNCEMENT.updated) * 1000).toLocaleString() : '')));
    }

    function publishAnnouncement() {
      var content = annContent.value;
      if (!content.trim()) {
        toast('公告内容不能为空', 'warn');
        return;
      }
      annResult.innerHTML = '';
      withLoading(function () {
        return request('admin.announcement.publish', {
          method: 'POST',
          body: { content: content, closeable: annCloseable.value === '1' }
        });
      }, '正在发布公告…').then(function (data) {
        setAnnouncement(data.announcement);
        annResult.appendChild(alertBox('ok', data.message || '公告已发布'));
        refreshAnnouncementStatus();
        toast('公告已发布', 'ok');
      }).catch(function (error) {
        handleAdminError(error, annResult);
      });
    }

    function clearAnnouncement() {
      annResult.innerHTML = '';
      withLoading(function () {
        return request('admin.announcement.clear', { method: 'POST', body: {} });
      }, '正在清除公告…').then(function (data) {
        setAnnouncement(null);
        annContent.value = '';
        annResult.appendChild(alertBox('ok', data.message || '公告已清除'));
        refreshAnnouncementStatus();
        toast('公告已清除', 'ok');
      }).catch(function (error) {
        handleAdminError(error, annResult);
      });
    }
    refreshAnnouncementStatus();

    var siteClosed = !!CFG.siteClosed;
    var siteStatus = el('div');
    var siteResult = el('div');
    var usersResult = el('div');
    var siteToggle = el('button', { class: 'dfb-btn', type: 'button', text: '关闭网站' });

    function renderSiteStatus() {
      siteStatus.innerHTML = '';
      siteStatus.appendChild(alertBox(
        siteClosed ? 'warn' : 'ok',
        siteClosed
          ? '网站当前已关闭：非管理员只能看到公告，且只能登录 / 退出登录。'
          : '网站当前开放：所有已登录用户可正常使用功能。'
      ));
      siteToggle.textContent = siteClosed ? '重新开放网站' : '关闭网站';
      siteToggle.className = siteClosed ? 'dfb-btn' : 'dfb-btn dfb-danger';
    }

    function toggleSite() {
      var next = !siteClosed;
      if (!window.confirm(next
        ? '确认关闭网站吗？关闭后非管理员将无法使用任何功能，只能登录/退出。'
        : '确认重新开放网站吗？')) return;
      siteResult.innerHTML = '';
      withLoading(function () {
        return request('admin.site.set', { method: 'POST', body: { closed: next } });
      }, next ? '正在关闭网站…' : '正在开放网站…').then(function (data) {
        siteClosed = !!data.siteClosed;
        CFG.siteClosed = siteClosed;
        renderSiteStatus();
        siteResult.appendChild(alertBox('ok', data.message || '站点状态已更新'));
        toast(siteClosed ? '网站已关闭' : '网站已开放', 'ok');
      }).catch(function (error) {
        handleAdminError(error, siteResult);
      });
    }
    siteToggle.addEventListener('click', toggleSite);
    renderSiteStatus();

    function renderUsers(users) {
      usersResult.innerHTML = '';
      if (!users.length) {
        usersResult.appendChild(emptyBox('当前没有已登录用户。'));
        return;
      }
      usersResult.appendChild(el('div', { class: 'dfb-meta', text: '共 ' + users.length + ' 个已登录用户' }));
      usersResult.appendChild(spacer(8));
      var list = el('div', { class: 'dfb-team-members' });
      users.forEach(function (user) {
        list.appendChild(el('div', { class: 'dfb-team-member' }, [
          el('div', { class: 'dfb-team-member-main' }, [
            el('div', { class: 'dfb-team-member-head' }, [
              el('strong', { class: 'dfb-team-member-name', text: user.userName || ('舞立方用户 ' + user.userId) }),
              user.isAdmin ? el('span', { class: 'dfb-team-role leader', text: '管理员' }) : null
            ]),
            el('div', { class: 'dfb-team-member-meta', text: 'ID ' + show(user.userId) + ' · 登录设备 ' + show(user.sessions) + ' 个' }),
            el('div', { class: 'dfb-team-member-meta', text: '最近更新 ' + (user.updated ? new Date(Number(user.updated) * 1000).toLocaleString() : '-') + ' · ' + (user.available ? '凭证有效' : '凭证待刷新') })
          ])
        ]));
      });
      usersResult.appendChild(list);
    }

    function loadUsers() {
      usersResult.innerHTML = '';
      withLoading(function () {
        return request('admin.users.list');
      }, '正在读取已登录用户…').then(function (data) {
        renderUsers(data.users || []);
      }).catch(function (error) {
        handleAdminError(error, usersResult);
      });
    }
    loadUsers();

    /* 后台动作失败时：解锁过期 / 资格失效就直接切回锁屏或拒绝页 */
    function handleAdminError(error, target) {
      var box = target || result;
      box.appendChild(alertBox('err', error.message));
      toast(error.message, 'err');
      if (error.data && error.data.admin && !error.data.admin.unlocked) {
        applyAdminState(error.data.admin);
        buildNav();
        renderRoute();
      }
    }

    function simpleAction(label, act, body) {
      return el('button', {
        class: 'dfb-btn dfb-ghost',
        type: 'button',
        text: label,
        onclick: function () {
          result.innerHTML = '';
          withLoading(function () {
            return request(act, { method: 'POST', body: body ? body() : {} });
          }, label + '中…').then(function (data) {
            result.appendChild(alertBox(data.ok === false ? 'warn' : 'ok', data.message || '操作完成'));
          }).catch(function (error) {
            handleAdminError(error);
          });
        }
      });
    }

    function refreshClientIds() {
      idResult.innerHTML = '';
      withLoading(function () {
        return request('admin.update.ids', { method: 'POST', body: { param: 'id' } });
      }, '正在读取客户端 ID…').then(function (data) {
        idResult.appendChild(alertBox(data.ok === false ? 'warn' : 'ok', data.message || '操作完成'));
        var ids = data.ids || [];
        if (ids.length) {
          idResult.appendChild(spacer(10));
          idResult.appendChild(el('pre', { class: 'dfb-mono', text: ids.join('\n') }));
        }
      }).catch(function (error) {
        handleAdminError(error, idResult);
      });
    }

    function showDefaultToken() {
      result.innerHTML = '';
      withLoading(function () {
        return request('token.default.show');
      }, '正在读取默认 Token…').then(function (data) {
        result.appendChild(alertBox('ok', data.message || '读取成功'));
        result.appendChild(spacer(10));
        result.appendChild(el('pre', { class: 'dfb-mono', text: data.text }));
      }).catch(function (error) {
        handleAdminError(error);
      });
    }

    function lockAdmin() {
      result.innerHTML = '';
      withLoading(function () {
        return request('admin.lock', { method: 'POST', body: {} });
      }, '正在锁定后台…').then(function (data) {
        applyAdminState(data.admin);
        buildNav();
        toast(data.message || '后台管理已锁定', 'ok');
        renderRoute();
      }).catch(function (error) {
        handleAdminError(error);
      });
    }

    function changePassword() {
      var current = currentPwdInput.value;
      var next = newPwdInput.value;
      if (!current) { toast('请输入当前密码', 'warn'); return; }
      if (!next) { toast('请输入新密码', 'warn'); return; }
      if (next !== confirmPwdInput.value) { toast('两次输入的新密码不一致', 'warn'); return; }
      pwdResult.innerHTML = '';
      withLoading(function () {
        return request('admin.password.change', {
          method: 'POST',
          body: { currentPassword: current, newPassword: next, confirmPassword: confirmPwdInput.value }
        });
      }, '正在修改后台密码…').then(function (data) {
        currentPwdInput.value = '';
        newPwdInput.value = '';
        confirmPwdInput.value = '';
        applyAdminState(data.admin);
        buildNav();
        toast(data.message || '后台密码已修改', 'ok');
        pwdResult.appendChild(alertBox('ok', data.message || '后台密码已修改'));
      }).catch(function (error) {
        handleAdminError(error, pwdResult);
      });
    }

    panel.appendChild(card('后台管理', '已通过密码验证，以下操作仅对管理员账号开放。', [
      el('div', { class: 'dfb-kv' }, [
        ['管理员', '舞立方 ID ' + show(admin.userId)],
        ['本次解锁', '剩余 ' + adminMinutes(admin.expiresIn)],
        ['凭证存储', 'data/state.php']
      ].map(function (pair) {
        return el('div', { class: 'dfb-kv-row' }, [
          el('span', { text: pair[0] }),
          el('span', { text: show(pair[1]) })
        ]);
      })),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '锁定后台', onclick: lockAdmin })
      ])
    ]));

    panel.appendChild(card('站点开关', '关闭后 Web 与 App 都只保留公告、登录和退出；管理员不受影响。', [
      siteStatus,
      spacer(12),
      el('div', { class: 'dfb-actions' }, [siteToggle]),
      spacer(12),
      siteResult
    ]));

    panel.appendChild(card('已登录用户', '读取 data/state.php 中保存的登录账号；只显示账号信息，不显示 Token。', [
      el('div', { class: 'dfb-actions' }, [
        el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '刷新用户列表', onclick: loadUsers })
      ]),
      spacer(12),
      usersResult
    ]));

    panel.appendChild(card('修改后台密码', '密码会以带随机盐的哈希保存；修改后其它已解锁会话会立即失效。', [
      el('div', { class: 'dfb-field-row' }, [
        field('当前密码', currentPwdInput),
        field('新密码（至少 ' + (admin.minLength || 8) + ' 位）', newPwdInput),
        field('确认新密码', confirmPwdInput)
      ]),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', { class: 'dfb-btn', type: 'button', text: '修改后台密码', onclick: changePassword })
      ]),
      spacer(12),
      pwdResult
    ]));

    panel.appendChild(card('公告管理', '发布后所有用户（含游客）都会在 Web 和 App 顶部看到；内容按纯文本保留换行。', [
      annStatus,
      spacer(12),
      field('公告内容', annContent),
      field('用户是否可关闭', annCloseable),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', { class: 'dfb-btn', type: 'button', text: '发布 / 更新公告', onclick: publishAnnouncement }),
        el('button', { class: 'dfb-btn dfb-ghost', type: 'button', text: '清除公告', onclick: clearAnnouncement })
      ]),
      spacer(12),
      annResult
    ]));

    panel.appendChild(card('客户端 ID 管理', '用于微信扫码登录。来源：config.php 的 dfb_client_ids，或 data/client_ids.json / .txt。', [
      el('div', { class: 'dfb-actions' }, [
        el('button', { class: 'dfb-btn', type: 'button', text: '刷新 / 读取客户端 ID', onclick: refreshClientIds })
      ]),
      spacer(14),
      idResult
    ]));

    panel.appendChild(card('凭证存储与默认 Token', '凭证保存在本站 data 目录，每次登录即时写入。', [
      el('div', { class: 'dfb-actions' }, [
        simpleAction('校验并保存（#save）', 'admin.tokens.save'),
        simpleAction('重新载入（#load）', 'admin.tokens.load'),
        el('button', {
          class: 'dfb-btn dfb-ghost',
          type: 'button',
          text: '查看默认 Token',
          onclick: showDefaultToken
        })
      ]),
      spacer(9),
      el('div', { class: 'dfb-actions' }, [
        simpleAction('清理登录状态 · 全部（#clearLogin all）', 'admin.clear.login', function () { return { param: 'all' }; }),
        simpleAction('清理登录状态 · 本账号（#clearLogin me）', 'admin.clear.login', function () { return { param: 'me' }; })
      ]),
      spacer(16),
      el('h3', { class: 'dfb-card-title', text: '设置默认 Token' }),
      el('p', { class: 'dfb-card-sub', text: '设置成功后，默认账号标识为 "0"。' }),
      el('div', { class: 'dfb-field-row' }, [
        field('Access Token', accessInput),
        field('Refresh Token', refreshInput)
      ]),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('button', {
          class: 'dfb-btn',
          type: 'button',
          text: '校验并保存默认 Token',
          onclick: function () {
            result.innerHTML = '';
            withLoading(function () {
              return request('admin.token.set', {
                method: 'POST',
                body: { accessToken: accessInput.value.trim(), refreshToken: refreshInput.value.trim() }
              });
            }, '正在校验默认 Token…').then(function (data) {
              toast(data.message || '设置成功', 'ok');
              result.appendChild(alertBox('ok', data.message || '设置成功'));
              result.appendChild(spacer(10));
              result.appendChild(el('pre', { class: 'dfb-mono', text: data.text }));
            }).catch(function (error) {
              handleAdminError(error);
            });
          }
        })
      ]),
      spacer(14),
      result
    ]));
  }

  /* -------------------------------------------------------------- 我的权限 */
  function mountPerms(panel) {
    var names = CFG.permNames || {};
    var admin = adminState();
    var rows = Object.keys(names).map(function (feature) {
      var ok = !!PERMS[feature];
      return el('div', { class: 'dfb-kv-row' }, [
        el('span', { text: names[feature] }),
        el('span', {}, [
          el('span', { class: 'dfb-badge ' + (ok ? 'perm-on' : 'perm-off'), text: ok ? '✅ 可用' : '⛔ 不可用' })
        ])
      ]);
    });

    var adminRows = [
      ['后台管理资格', admin.eligible ? '✅ 管理员账号' : '⛔ 普通账号'],
      ['后台管理状态', admin.unlocked ? '🔓 已通过密码' : (admin.eligible ? (admin.passwordSet === false ? '🆕 待设置密码' : '🔒 待输入密码') : '—')]
    ];
    if (admin.eligible) {
      adminRows.push(['管理员舞立方 ID', admin.userId]);
    }

    panel.appendChild(card('我的身份', '身份由浏览器 Cookie 自动分配，不需要注册或登录账号。', [
      el('div', { class: 'dfb-kv' }, [
        ['浏览器身份', CFG.username],
        ['身份标识', CFG.account],
        ['运行模式', '独立网页版'],
        ['站点版本', CFG.version || '-']
      ].map(function (pair) {
        return el('div', { class: 'dfb-kv-row' }, [
          el('span', { text: pair[0] }),
          el('span', { text: show(pair[1]) })
        ]);
      })),
      spacer(14),
      el('div', { class: 'dfb-kv' }, adminRows.map(function (pair) {
        return el('div', { class: 'dfb-kv-row' }, [
          el('span', { text: pair[0] }),
          el('span', { text: show(pair[1]) })
        ]);
      })),
      spacer(14),
      el('div', { class: 'dfb-alert info', text:
        '身份标识（' + CFG.account + '）保存在浏览器 Cookie 里，只用于在本站区分不同访问者的登录凭证。\n' +
        '所有凭证都保存在服务器上，其它访问者无法通过页面读取你的 Token。\n' +
        '清除浏览器 Cookie 会得到一个全新身份，需要重新扫码登录舞立方。' })
    ]));

    panel.appendChild(card('功能状态', '后台管理只对 config.php 中 admin_user_ids 里的舞立方账号开放，且需要通过密码验证。', [
      el('div', { class: 'dfb-kv' }, rows)
    ]));
  }

  /* -------------------------------------------------------------- 功能说明 */
  function mountHelp(panel) {
    var holder = el('div');
    panel.appendChild(card('功能说明', '正在加载…', [holder]));

    request('help').then(function (data) {
      holder.innerHTML = '';
      (data.groups || []).forEach(function (group) {
        holder.appendChild(el('div', { class: 'dfb-help-group' }, [
          el('h3', { class: 'dfb-card-title', text: group.group }),
          el('div', {}, (group.items || []).map(function (item) {
            return el('div', { class: 'dfb-help-item' }, [
              el('code', { text: item.cmd }),
              el('span', { text: item.desc })
            ]);
          }))
        ]));
      });
      holder.appendChild(alertBox('info', data.note || ''));
      holder.appendChild(spacer(12));
      holder.appendChild(el('div', { class: 'dfb-actions' }, [
        el('a', { class: 'dfb-btn dfb-ghost', href: data.manualUrl || CFG.manualUrl, target: '_blank', rel: 'noopener noreferrer', text: '打开在线使用手册' })
      ]));
    }).catch(function (error) {
      holder.innerHTML = '';
      holder.appendChild(alertBox('err', error.message));
    });
  }

  /* ================================================================= 路由 */
  function currentRoute() {
    var hash = location.hash.replace(/^#/, '');
    if (!hash) return '/';
    if (hash.charAt(0) !== '/') hash = '/' + hash;
    return Object.prototype.hasOwnProperty.call(ROUTES, hash) ? hash : '/';
  }

  function mountDenied(panel, route) {
    var config = ROUTES[route];
    var feature = config ? config.feature : 'access';
    var isAdmin = feature === 'admin';
    var message = isAdmin
      ? (adminState().reason || '当前账号没有后台管理权限。')
      : ('当前站点已关闭「' + featureName(feature) + '」功能。\n如需开启，请检查 config.php 中的相关开关，然后刷新页面。');
    panel.appendChild(card(isAdmin ? '后台管理不可用' : '功能未开放', null, [
      alertBox('warn', message),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        isAdmin ? el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/login', text: '去登录管理员账号' }) : null,
        el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/perms', text: '查看我的身份' }),
        el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/', text: '返回功能总览' })
      ])
    ]));
  }

  function mountBanned(panel) {
    panel.appendChild(card('账号不可用', null, [
      alertBox('err', '当前身份已被限制，无法使用本站功能。'),
      spacer(12),
      el('div', { class: 'dfb-actions' }, [
        el('a', { class: 'dfb-btn dfb-ghost', href: PLUGIN_URL + '#/perms', text: '查看我的身份' })
      ])
    ]));
  }

  function renderRoute() {
    clearTimers();
    var route = currentRoute();

    /* 游客只允许登录页；强制访问其它功能一律回登录界面 */
    if (!LOGGED_IN && route !== '/login') {
      if (location.hash !== '#/login') {
        try {
          history.replaceState(null, '', '#/login');
        } catch (e) {
          location.hash = '#/login';
        }
      }
      route = '/login';
    }

    /* 站点关闭：非管理员登录后只能停留关闭页 / 退出页 */
    if (CFG.siteClosed && !isAdminUser() && LOGGED_IN
      && route !== '/closed' && route !== '/logout' && route !== '/login') {
      try {
        history.replaceState(null, '', '#/closed');
      } catch (e) {
        location.hash = '#/closed';
      }
      route = '/closed';
    }

    var config = ROUTES[route] || ROUTES['/'];

    var titleNode = $('#dfbPageTitle');
    var descNode = $('#dfbPageDesc');
    if (titleNode) titleNode.textContent = config.title;
    if (descNode) descNode.textContent = config.desc;

    var links = document.querySelectorAll('.dfb-nav-link');
    Array.prototype.forEach.call(links, function (link) {
      if (link.dataset.route === route) link.classList.add('active');
      else link.classList.remove('active');
    });

    var panel = $('#dfbPanel');
    if (!panel) return;
    panel.innerHTML = '';

    /* 被封禁的用户：无论访问哪个路由，都只显示封禁提示 */
    if (CFG.banned) {
      mountBanned(panel);
      return;
    }

    if (config.feature && !canUse(config.feature)) {
      mountDenied(panel, route);
      return;
    }
    try {
      config.mount(panel);
    } catch (error) {
      panel.appendChild(alertBox('err', '页面渲染失败：' + error.message));
    }
    if (window.DFB_I18N) {
      window.DFB_I18N.translateDom(panel);
      setTimeout(function () { window.DFB_I18N.translateDom(panel); }, 0);
    }
    buildMobileNav();
  }

  /* ================================================================= 初始化 */
  function init() {
    /* 关键：进入页面先确保 Loading 遮罩关闭。
       （样式表里如有 display 声明覆盖了 [hidden]，这里会实测并纠正） */
    forceHideLoading();
    initTheme();

    /* 手机开屏默认进入个人信息；桌面仍保留功能总览 */
    if (isMobileLayout()) {
      var initialHash = (location.hash || '').replace(/^#/, '');
      if (!initialHash || initialHash === '/') {
        try { history.replaceState(null, '', '#/user-info'); } catch (e) { location.hash = '#/user-info'; }
      }
    }

    var nameNode = $('#dfbUserName');
    if (nameNode) nameNode.textContent = '游客';

    buildNav();
    initMobileNavDrag();
    renderAnnouncement();

    /* 手机端抽屉菜单：汉堡按钮 toggle，点击导航链接后自动关闭 */
    var menuToggle = $('#dfbMenuToggle');
    if (menuToggle) {
      menuToggle.addEventListener('click', function () {
        document.body.classList.toggle('dfb-drawer-open');
        menuToggle.setAttribute('aria-expanded',
          document.body.classList.contains('dfb-drawer-open') ? 'true' : 'false');
      });
    }
    /* 点击导航链接或页面其他区域后关闭抽屉 */
    document.addEventListener('click', function (e) {
      if (!document.body.classList.contains('dfb-drawer-open')) return;
      if (e.target.closest('.dfb-menu-toggle')) return;
      if (e.target.closest('.dfb-sidebar-foot')) return;
      document.body.classList.remove('dfb-drawer-open');
      if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    });

    var refreshBtn = $('#dfbRefreshBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshState(false).then(function (info) {
          if (info && info.loggedIn) {
            toast('已登录 · ' + (info.userName || ('舞立方 ID ' + info.userId)), 'ok');
          } else {
            toast('当前账号未登录', 'warn');
          }
        });
      });
    }

    window.addEventListener('hashchange', renderRoute);
    renderRoute();
    refreshState(true);

    /* 首帧后再次确认遮罩已关闭，防止模板/样式造成遮挡 */
    setTimeout(forceHideLoading, 0);
    setTimeout(forceHideLoading, 600);
  }

  /* 任何未捕获的脚本错误都不应把用户永久留在加载界面 */
  window.addEventListener('error', function () {
    if (loadingDepth > 0) forceHideLoading();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

"use strict";

let docsEditorWrap = true;

function defineDocsEditorMode() {
  if (CodeMirror.modes?.['picapi-markdown-html']) return;
  CodeMirror.defineMode('picapi-markdown-html', config => {
    const html = CodeMirror.getMode(config, 'htmlmixed');
    const plain = CodeMirror.getMode(config, 'text/plain');
    const codeMode = language => {
      const name = String(language || '').toLowerCase();
      if (['html', 'html5', 'xml', 'svg'].includes(name)) return html;
      if (['css', 'scss', 'less'].includes(name)) return CodeMirror.getMode(config, 'css');
      if (['js', 'javascript', 'typescript', 'ts'].includes(name)) return CodeMirror.getMode(config, 'javascript');
      return plain;
    };
    const newInnerState = mode => CodeMirror.startState(mode);
    return {
      startState() { return { fence: null, fenceMode: null, fenceState: null, rawTag: null, rawDepth: 0, rawState: newInnerState(html) }; },
      copyState(state) { return { ...state, fenceState: state.fenceMode && CodeMirror.copyState(state.fenceMode, state.fenceState), rawState: state.rawTag && CodeMirror.copyState(html, state.rawState) }; },
      token(stream, state) {
        if (state.fence) {
          const close = stream.string.match(/^ {0,3}(`+|~+)\s*$/)?.[1];
          if (stream.sol() && close && close[0] === state.fence.char && close.length >= state.fence.length) {
            stream.skipToEnd(); state.fence = null; state.fenceMode = null; state.fenceState = null; return 'md-fence';
          }
          return state.fenceMode.token(stream, state.fenceState);
        }
        if (state.rawTag) {
          if (stream.sol()) {
            const line = stream.string, tag = state.rawTag;
            const opens = line.match(new RegExp('<' + tag + '\\b[^>]*>', 'gi')) || [];
            const closes = line.match(new RegExp('</' + tag + '\\s*>', 'gi')) || [];
            state.rawDepth += opens.filter(open => !/\/\s*>$/.test(open)).length - closes.length;
          }
          const style = html.token(stream, state.rawState);
          if (stream.eol() && state.rawDepth <= 0) { state.rawTag = null; state.rawDepth = 0; state.rawState = newInnerState(html); }
          return style;
        }
        if (stream.sol()) {
          const fence = stream.match(/^ {0,3}(`{3,}|~{3,})\s*([\w+-]*)[^\n]*$/);
          if (fence) {
            const marker = fence[1];
            state.fence = { char: marker[0], length: marker.length };
            state.fenceMode = codeMode(fence[2]);
            state.fenceState = newInnerState(state.fenceMode);
            return 'md-fence';
          }
          const raw = stream.string.match(/^\s*<(style|script|section|article|header|footer|aside|div|table|figure|details|form|nav|main|p|ul|ol|blockquote|pre|h[1-6])\b/i);
          if (raw) {
            state.rawTag = raw[1].toLowerCase();
            const line = stream.string, tag = state.rawTag;
            const opens = line.match(new RegExp('<' + tag + '\\b[^>]*>', 'gi')) || [];
            const closes = line.match(new RegExp('</' + tag + '\\s*>', 'gi')) || [];
            state.rawDepth = opens.filter(open => !/\/\s*>$/.test(open)).length - closes.length;
            state.rawState = newInnerState(html);
            const style = html.token(stream, state.rawState);
            if (stream.eol() && state.rawDepth <= 0) { state.rawTag = null; state.rawDepth = 0; state.rawState = newInnerState(html); }
            return style;
          }
          if (stream.match(/^\s*#{1,6}(?=\s)/)) return 'md-heading';
          if (stream.match(/^\s*>/)) return 'md-quote';
          if (stream.match(/^\s*(?:[-+*]|\d+[.)])\s/)) return 'md-list';
        }
        if (stream.match(/\*\*[^*]+\*\*|__[^_]+__/)) return 'md-strong';
        if (stream.match(/`[^`]+`/)) return 'md-inline-code';
        if (stream.match(/!?\[[^\]]+\]\([^)]*\)/)) return 'md-link';
        stream.next();
        return null;
      },
      innerMode(state) {
        if (state.fenceMode) return { mode: state.fenceMode, state: state.fenceState };
        if (state.rawTag) return CodeMirror.innerMode(html, state.rawState);
        return { mode: plain, state: null };
      }
    };
  });
}

function leaveDocsCodeEditor(editor, backwards = false) {
  const wrapper = editor.getWrapperElement();
  const controls = [...document.querySelectorAll('a[href],button,input,select,textarea,summary,iframe,[tabindex]')]
    .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length && !element.closest('.CodeMirror'));
  const direction = backwards ? Node.DOCUMENT_POSITION_PRECEDING : Node.DOCUMENT_POSITION_FOLLOWING;
  const target = (backwards ? controls.reverse() : controls).find(element => wrapper.compareDocumentPosition(element) & direction);
  if (target) target.focus(); else editor.getInputField().blur();
}

async function mountDocsCodeEditor() {
  const textarea = document.querySelector('#docs-markdown');
  if (!textarea || textarea.dataset.codeMounted) return;
  textarea.dataset.codeMounted = 'loading';
  try {
    await loadHTMLEditor();
    if (!textarea.isConnected) return;
    defineDocsEditorMode();
    let escapeTab = false;
    const editor = CodeMirror.fromTextArea(textarea, {
      mode: 'picapi-markdown-html', theme: 'picapi', lineNumbers: true, lineWrapping: docsEditorWrap,
      indentUnit: 2, tabSize: 2, indentWithTabs: false, autoCloseTags: true,
      viewportMargin: 16, inputStyle: 'textarea', screenReaderLabel: 'Markdown 源码编辑器',
      extraKeys: {
        'Esc': () => { escapeTab = true; },
        'Tab': instance => {
          if (escapeTab) { escapeTab = false; leaveDocsCodeEditor(instance); return; }
          if (instance.somethingSelected()) instance.indentSelection('add');
          else instance.replaceSelection('  ', 'end', '+input');
        },
        'Shift-Tab': instance => {
          if (escapeTab) { escapeTab = false; leaveDocsCodeEditor(instance, true); return; }
          instance.indentSelection('subtract');
        },
        'Ctrl-S': () => document.querySelector('#docs-config-form')?.requestSubmit(),
        'Cmd-S': () => document.querySelector('#docs-config-form')?.requestSubmit()
      }
    });
    textarea._docsEditor = editor;
    textarea.dataset.codeMounted = 'ready';
    const position = () => {
      const cursor = editor.getCursor();
      const output = document.querySelector('[data-docs-cursor]');
      if (output) output.textContent = `第 ${cursor.line + 1} 行 · 第 ${cursor.ch + 1} 列`;
    };
    editor.on('change', () => {
      editor.save();
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      const status = document.querySelector('.docs-save-state');
      if (status) status.textContent = '有未保存的修改';
    });
    editor.on('cursorActivity', position);
    editor.refresh();
    position();
  } catch (error) {
    if (textarea.isConnected) {
      delete textarea.dataset.codeMounted;
      const state = document.querySelector('.docs-save-state');
      if (state) state.textContent = '代码编辑器未加载，可直接编辑文本';
    }
  }
}

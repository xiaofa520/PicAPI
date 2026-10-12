"use strict";
const htmlEditorBase=new URL('.',document.currentScript.src);
let htmlEditorAssets=null,mailCodeWrap=true;
function loadHTMLScript(path){return new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=new URL(path,htmlEditorBase).href;script.onload=resolve;script.onerror=()=>{script.remove();reject(new Error('代码编辑器加载失败，请重新进入此页。'));};document.head.append(script);});}
function loadHTMLEditor(){
  if(!htmlEditorAssets)htmlEditorAssets=(async()=>{
    if(!window.CodeMirror)await loadHTMLScript('vendor/codemirror/lib/codemirror.js');
    for(const path of ['mode/xml/xml.js','mode/javascript/javascript.js','mode/css/css.js','mode/htmlmixed/htmlmixed.js','addon/edit/closetag.js'])await loadHTMLScript('vendor/codemirror/'+path);
    if(!window.prettier)await loadHTMLScript('vendor/prettier/standalone.js');
    if(!window.prettierPlugins?.html)await loadHTMLScript('vendor/prettier/plugins/html.js');
  })().catch(error=>{htmlEditorAssets=null;throw error;});
  return htmlEditorAssets;
}
function mailCodeEditorMarkup(source){return `<div class="field mail-code-field"><label id="mail-html-label" for="mail-html">HTML 模板</label><div class="html-code-shell"><div class="html-code-toolbar"><span>HTML5</span><div><button type="button" class="text-button" data-html-format disabled title="格式化 HTML（Ctrl / Cmd + Shift + F）">${icon('code')}格式化</button><button type="button" class="text-button" data-html-wrap aria-pressed="${mailCodeWrap}">自动换行</button></div></div><textarea id="mail-html" class="mail-source" rows="20" spellcheck="false" aria-describedby="html-editor-hint">${escapeHTML(source)}</textarea><div class="html-code-footer"><span data-html-position>第 1 行，第 1 列</span><span>2 空格缩进</span></div></div><small id="html-editor-hint">Tab 缩进，Esc 后按 Tab 离开编辑器。</small><small data-html-status role="status" aria-live="polite"></small></div>`;}
function leaveMailCodeEditor(editor,backward=false){
  const wrapper=editor.getWrapperElement();
  const controls=[...document.querySelectorAll('a[href],button,input,select,textarea,summary,iframe,[tabindex]')].filter(el=>!el.disabled&&el.tabIndex>=0&&el.getClientRects().length&&!el.closest('.CodeMirror'));
  const direction=backward?Node.DOCUMENT_POSITION_PRECEDING:Node.DOCUMENT_POSITION_FOLLOWING;
  const target=(backward?controls.reverse():controls).find(el=>wrapper.compareDocumentPosition(el)&direction);
  if(target)target.focus();else editor.getInputField().blur();
}
async function mountMailCodeEditor(){
  const textarea=document.querySelector('#mail-html');if(!textarea||textarea.dataset.codeMounted)return;
  textarea.dataset.codeMounted='loading';const field=textarea.closest('.mail-code-field');if(!field)return;
  const kind=preferences.mailKind,status=field.querySelector('[data-html-status]');
  try{
    await loadHTMLEditor();if(!textarea.isConnected)return;
    let escapeTab=false;
    const editor=CodeMirror.fromTextArea(textarea,{
      mode:'htmlmixed',theme:'picapi',lineNumbers:true,lineWrapping:mailCodeWrap,indentUnit:2,tabSize:2,indentWithTabs:false,
      autoCloseTags:true,viewportMargin:10,inputStyle:'textarea',screenReaderLabel:'HTML5 邮件模板代码编辑器',
      extraKeys:{
        'Ctrl-Shift-F':()=>format(false),'Cmd-Shift-F':()=>format(false),
        'Esc':()=>{escapeTab=true;},
        'Tab':cm=>{if(escapeTab){escapeTab=false;leaveMailCodeEditor(cm);return;}if(cm.somethingSelected())cm.indentSelection('add');else cm.replaceSelection('  ','end','+input');},
        'Shift-Tab':cm=>{if(escapeTab){escapeTab=false;leaveMailCodeEditor(cm,true);return;}cm.indentSelection('subtract');}
      }
    });
    textarea._htmlEditor=editor;textarea.dataset.codeMounted='ready';
    editor.addOverlay({token(stream){if(stream.match(/\{\{[^}]*\}\}/))return 'template-variable';while(stream.next()!=null&&!stream.match(/\{\{/ ,false)){}return null;}});
    const position=()=>{const cursor=editor.getCursor();field.querySelector('[data-html-position]').textContent=`第 ${cursor.line+1} 行，第 ${cursor.ch+1} 列`;};
    let revision=0,formatting=false;
    editor.on('change',()=>{revision++;editor.save();textarea.dispatchEvent(new Event('input',{bubbles:true}));status.textContent='';});
    editor.on('cursorActivity',position);
    async function format(automatic){
      if(formatting)return;
      formatting=true;const button=field.querySelector('[data-html-format]');button.disabled=true;
      const source=editor.getValue(),version=revision;
      try{
        const formatted=await prettier.format(source,{parser:'html',plugins:[prettierPlugins.html],tabWidth:2,useTabs:false,printWidth:88,htmlWhitespaceSensitivity:'css',embeddedLanguageFormatting:'off',endOfLine:'lf'});
        if(!textarea.isConnected||kind!==preferences.mailKind||revision!==version)return;
        if(formatted!==source){const cursor=editor.getCursor();editor.operation(()=>{editor.setValue(formatted);editor.setCursor(cursor);});}
        if(!automatic)status.textContent='HTML 格式已整理';
      }catch(error){
        if(textarea.isConnected&&revision===version)status.textContent='暂时无法格式化，请检查 HTML 标签是否完整；原代码已保留。';
      }finally{formatting=false;if(button.isConnected)button.disabled=false;}
    }
    field.querySelector('[data-html-format]').onclick=()=>format(false);
    const wrapButton=field.querySelector('[data-html-wrap]');wrapButton.onclick=()=>{mailCodeWrap=!mailCodeWrap;editor.setOption('lineWrapping',mailCodeWrap);wrapButton.setAttribute('aria-pressed',String(mailCodeWrap));};
    editor.refresh();position();await format(true);
  }catch(error){if(textarea.isConnected){status.textContent=error.message+' 仍可在文本框中编辑。';delete textarea.dataset.codeMounted;}}
}

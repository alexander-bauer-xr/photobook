/*
Copilot prompt:
Simple sidebar with page info, swap item up/down, replace stub, and quick template change.
*/
import React from 'react';
import { useSelection } from '../state/selection';
import { useTemplates } from '../hooks/useTemplates';
import type { LayoutTemplate } from '../api/types';
type Props = { page: any; onSwap: (a:number,b:number)=>void; onReplace:(i:number)=>void; onTemplateChange:(id:string)=>void };

export default function Sidebar({ page, onSwap, onReplace, onTemplateChange }: Props) {
  const { setSelected } = useSelection();
   const templatesQ = useTemplates();
  const templateGroups = React.useMemo(() => {
    const entries = Object.entries(templatesQ.data ?? {}) as [string, LayoutTemplate[]][];
    const all = entries
      .map(([count, templates]) => ({ count: Number(count), templates }))
      .sort((a, b) => a.count - b.count);
    const wanted = Number.isFinite(Number(page?.items?.length)) ? Number(page.items.length) : null;
    if (!wanted) return all;
    // Only show the group matching the current number of items for this page
    return all.filter(g => g.count === wanted);
  }, [templatesQ.data, page?.items?.length]);
  const currentTemplateId = page.templateId || page.template || '';
  return (
    <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex flex-col gap-3">
      <div>
        <div className="text-sm text-neutral-500">Page</div>
        <div className="font-semibold">#{page.n}</div>
        <div className="text-xs text-neutral-500 truncate">Template: {page.templateId || page.template || 'generic'}</div>
      </div>

      <div>
        <div className="text-sm font-medium">Templates for {page.items?.length || 0} photo{(page.items?.length||0) === 1 ? '' : 's'}</div>
        {templatesQ.isLoading && <div className="text-xs text-neutral-500 mt-1">Loading templates…</div>}
        {templatesQ.isError && <div className="text-xs text-red-500 mt-1">Unable to load templates.</div>}
        {!templatesQ.isLoading && !templatesQ.isError && templateGroups.length === 0 && (
          <div className="text-xs text-neutral-500 mt-1">No templates found.</div>
        )}
        <div className="mt-2 flex flex-col gap-3 max-h-48 overflow-auto pr-1">
          {templateGroups.map(group => (
            <div key={group.count}>
              <div className="text-[11px] uppercase tracking-wide text-neutral-500">{group.count} photo{group.count === 1 ? '' : 's'}</div>
              <div className="mt-1 flex flex-wrap gap-2">
                {group.templates.map((tpl) => {
                  const active = tpl.id === currentTemplateId;
                  return (
                    <button
                      key={tpl.id}
                      className={`px-3 py-1 rounded text-sm border transition-colors ${active ? 'bg-neutral-800 text-white border-neutral-800' : 'bg-neutral-100 text-neutral-800 border-neutral-200 hover:bg-neutral-200'}`}
                      onClick={() => onTemplateChange(tpl.id)}
                    >
                      {tpl.id}
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      </div>

      <div>
        <div className="text-sm font-medium">Items</div>
        <ul className="mt-2 flex flex-col gap-2">
          {page.items.map((it: any, i: number)=>(
            <li key={i} className="flex items-center gap-2">
              <div className="w-12 h-10 bg-neutral-100 rounded overflow-hidden">
                {(() => { const u = (it as any).webSrc || (it as any).web || it.src; return u ? <img src={u} alt="thumb" className="w-full h-full object-cover"/> : null; })()}
              </div>
              <div className="text-xs flex-1">
                <div>slot {it.slotIndex}</div>
                <div className="text-neutral-500">{it.photo?.filename || '—'}</div>
              </div>
              <button className="text-xs px-2 py-1 bg-neutral-200 rounded" onClick={()=>{ setSelected(`${page.n}:${i}`); onReplace(i); }}>Replace</button>
              {i>0 && <button className="text-xs px-2 py-1 bg-neutral-800 text-white rounded" onClick={()=>onSwap(i,i-1)}>↑</button>}
              {i<page.items.length-1 && <button className="text-xs px-2 py-1 bg-neutral-800 text-white rounded" onClick={()=>onSwap(i,i+1)}>↓</button>}
            </li>
          ))}
        </ul>
      </div>
    </aside>
  );
}

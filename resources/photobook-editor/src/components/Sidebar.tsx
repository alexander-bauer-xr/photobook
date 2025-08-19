/*
Copilot prompt:
Simple sidebar with page info, swap item up/down, replace stub, and quick template change.
*/
import React from 'react';
import { useSelection } from '../state/selection';
type Props = { page: any; onSwap: (a:number,b:number)=>void; onReplace:(i:number)=>void; onTemplateChange:(id:string)=>void };

export default function Sidebar({ page, onSwap, onReplace, onTemplateChange }: Props) {
  const { setSelected } = useSelection();
  return (
    <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex flex-col gap-3">
      <div>
        <div className="text-sm text-neutral-500">Page</div>
        <div className="font-semibold">#{page.n}</div>
        <div className="text-xs text-neutral-500 truncate">Template: {page.templateId || page.template || 'generic'}</div>
      </div>

      <div className="flex gap-2">
        <button className="px-3 py-1 rounded bg-neutral-800 text-white text-sm" onClick={()=>onTemplateChange('4/quad')}>4/quad</button>
        <button className="px-3 py-1 rounded bg-neutral-200 text-sm" onClick={()=>onTemplateChange('4/hero-row')}>4/hero-row</button>
      </div>

      <div>
        <div className="text-sm font-medium">Items</div>
        <ul className="mt-2 flex flex-col gap-2">
          {page.items.map((it,i)=>(
            <li key={i} className="flex items-center gap-2">
              <div className="w-12 h-10 bg-neutral-100 rounded overflow-hidden">
                {(() => { const u = (it as any).web || (it as any).webSrc || it.src; return u ? <img src={u} alt="thumb" className="w-full h-full object-cover"/> : null; })()}
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

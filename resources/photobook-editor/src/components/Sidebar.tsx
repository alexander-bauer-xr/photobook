/*
Copilot prompt:
Simple sidebar with page info, swap item up/down, replace stub, and quick template change.
*/
import React from 'react';
import { useSelection } from '../state/selection';
import { useTemplates } from '../hooks/useTemplates';
import type { LayoutTemplate } from '../api/types';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { Input } from './ui/input';
import { ScrollArea } from './ui/scroll-area';

type Props = {
  page: any;
  onSwap: (a:number,b:number)=>void;
  onReplace:(i:number)=>void;
  onTemplateChange:(id:string)=>void;
  onLayoutPreferenceChange:(preferred:boolean)=>void;
  onUpdateItem: (idx:number, changes:Record<string, any>) => void;
};

export default function Sidebar({ page, onSwap, onReplace, onTemplateChange, onLayoutPreferenceChange, onUpdateItem }: Props) {
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
  const layoutPreferred = page?.layoutFeedback?.preferred === true
    && (!page?.layoutFeedback?.templateId || page.layoutFeedback.templateId === currentTemplateId);
  const isCover = page?.id === 'cover' || currentTemplateId === 'cover';
  return (
    <aside className="flex h-full min-h-0 w-[22rem] shrink-0 border-l border-neutral-200/80 bg-neutral-50/60 p-4 backdrop-blur-sm">
      <div className="flex h-full min-h-0 flex-col gap-4">
        <Card className="rounded-[26px] border-neutral-200/80 shadow-sm">
          <CardHeader className="pb-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-[11px] font-medium uppercase tracking-[0.18em] text-neutral-500">Inspector</div>
                <CardTitle className="mt-2 text-xl">Page #{page.n}</CardTitle>
              </div>
              <Badge>{page.items?.length || 0} photo{(page.items?.length||0) === 1 ? '' : 's'}</Badge>
            </div>
            <div className="text-sm text-neutral-500 truncate">
              Template: {page.templateId || page.template || 'generic'}
            </div>
            {!isCover && (
              <Button
                size="sm"
                variant={layoutPreferred ? 'success' : 'outline'}
                disabled={!currentTemplateId}
                onClick={() => onLayoutPreferenceChange(!layoutPreferred)}
                title="Tell the generator to prefer this layout for future rebuilds."
                className="mt-4 w-full"
              >
                {layoutPreferred ? 'Preferred layout' : 'Prefer this layout'}
              </Button>
            )}
          </CardHeader>
        </Card>

        <Card className="rounded-[26px] border-neutral-200/80 shadow-sm">
          <CardHeader className="pb-4">
            <CardTitle className="text-base">Templates</CardTitle>
            <div className="text-sm text-neutral-500">
              Try layouts for the current photo count without leaving the editor.
            </div>
          </CardHeader>
          <CardContent className="pt-0">
            {templatesQ.isLoading && <div className="text-sm text-neutral-500">Loading templates…</div>}
            {templatesQ.isError && <div className="text-sm text-red-600">Unable to load templates.</div>}
            {!templatesQ.isLoading && !templatesQ.isError && templateGroups.length === 0 && (
              <div className="text-sm text-neutral-500">No templates found.</div>
            )}
            <ScrollArea className="max-h-72 pr-2">
              <div className="space-y-4">
                {templateGroups.map(group => (
                  <div key={group.count}>
                    <div className="mb-2 text-[11px] font-medium uppercase tracking-[0.16em] text-neutral-500">
                      {group.count} photo{group.count === 1 ? '' : 's'}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {group.templates.map((tpl) => {
                        const active = tpl.id === currentTemplateId;
                        return (
                          <Button
                            key={tpl.id}
                            size="sm"
                            variant={active ? 'default' : 'outline'}
                            onClick={() => onTemplateChange(tpl.id)}
                          >
                            {tpl.id}
                          </Button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            </ScrollArea>
          </CardContent>
        </Card>

        <Card className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-[26px] border-neutral-200/80 shadow-sm">
          <CardHeader className="pb-4">
            <CardTitle className="text-base">Items</CardTitle>
            <div className="text-sm text-neutral-500">
              Reorder, replace, and caption the photos assigned to this page.
            </div>
          </CardHeader>
          <CardContent className="flex min-h-0 flex-1 flex-col overflow-hidden pt-0">
            <ScrollArea className="min-h-0 flex-1 pr-2">
              <ul className="space-y-3">
                {page.items.map((it: any, i: number)=>(
                  <li key={i} className="rounded-2xl border border-neutral-200 bg-neutral-50/70 p-3">
                    <div className="flex items-start gap-3">
                      <div className="h-14 w-16 shrink-0 overflow-hidden rounded-xl bg-neutral-200">
                        {(() => {
                          const u = (it as any).webSrc || (it as any).web || it.src;
                          return u ? <img src={u} alt="thumb" className="h-full w-full object-cover" /> : null;
                        })()}
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <Badge>slot {it.slotIndex}</Badge>
                        </div>
                        <div className="mt-2 truncate text-sm font-medium text-neutral-900">
                          {it.photo?.filename || 'Untitled photo'}
                        </div>
                      </div>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setSelected(`${page.n}:${i}`);
                          onReplace(i);
                        }}
                      >
                        Replace
                      </Button>
                      {i > 0 && (
                        <Button size="sm" variant="secondary" onClick={() => onSwap(i, i - 1)}>
                          Move up
                        </Button>
                      )}
                      {i < page.items.length - 1 && (
                        <Button size="sm" variant="secondary" onClick={() => onSwap(i, i + 1)}>
                          Move down
                        </Button>
                      )}
                    </div>
                    <Input
                      type="text"
                      value={typeof it.caption === 'string' ? it.caption : ''}
                      onChange={(e) => onUpdateItem(i, { caption: e.target.value })}
                      className="mt-3 h-9 text-xs"
                      placeholder="Caption (emoji ok)"
                    />
                  </li>
                ))}
              </ul>
            </ScrollArea>
          </CardContent>
        </Card>
      </div>
    </aside>
  );
}

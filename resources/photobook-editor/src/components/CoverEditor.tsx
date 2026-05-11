import React from 'react';
import { useMutation } from '@tanstack/react-query';
import { PB } from '../lib/api';

export default function CoverEditor({ hash, currentTitle, currentImage }: { hash:string, currentTitle?:string, currentImage?:string }) {
  const setCover = useMutation({ mutationFn: (payload:any)=>PB.setCover(hash, payload) });

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]; if (!file) return;
    const form = new FormData();
    form.append('file', file);
    const res = await fetch(`/api/photobook/upload/${hash}`, { method:'POST', body: form });
    const data = await res.json();
    await setCover.mutateAsync({ image: data.path, title: currentTitle || '' });
  }

  return (
    <div className="p-4 border rounded">
      <label className="block text-sm mb-1">Titel</label>
      <input className="border px-2 py-1 w-full mb-3" defaultValue={currentTitle || ''} onBlur={(e)=>setCover.mutate({ image: currentImage || '', title: e.currentTarget.value })} />
      <label className="block text-sm mb-1">Cover-Bild</label>
      <input type="file" accept="image/*" onChange={onFile}/>
    </div>
  );
}

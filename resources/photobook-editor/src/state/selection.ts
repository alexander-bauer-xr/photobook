/*
Copilot prompt:
Zustand store to track selected item key ("page:item").
Expose selectedItemKey and setSelected().
*/
import { create } from 'zustand';

type SelState = { selectedItemKey: string | null; setSelected: (k: string | null) => void; };
export const useSelection = create<SelState>((set)=>({ selectedItemKey:null, setSelected:(k)=>set({selectedItemKey:k}) }));

import { Card, CardContent } from '../../../components/ui/card';
import { Checkbox } from '../../../components/ui/checkbox';
import { Input } from '../../../components/ui/input';
import { Label } from '../../../components/ui/label';

type CoverEditorBarProps = {
    coverTitle: string;
    coverSubtitle: string;
    coverDateText: string;
    coverShowDate: boolean;
    coverWebSrc: string | null;
    onCoverTitleChange: (value: string) => void;
    onCoverSubtitleChange: (value: string) => void;
    onCoverDateTextChange: (value: string) => void;
    onCoverShowDateChange: (checked: boolean) => void;
};

export default function CoverEditorBar({
    coverTitle,
    coverSubtitle,
    coverDateText,
    coverShowDate,
    coverWebSrc,
    onCoverTitleChange,
    onCoverSubtitleChange,
    onCoverDateTextChange,
    onCoverShowDateChange,
}: CoverEditorBarProps) {
    return (
        <div className="flex-none border-b border-neutral-200/70 bg-white/70 px-4 py-4">
            <Card className="rounded-[26px] border-neutral-200/80 bg-white/90 shadow-sm">
                <CardContent className="grid gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.85fr)_auto]">
                    <div className="xl:col-span-full">
                        <div className="text-[11px] font-medium uppercase tracking-[0.16em] text-neutral-500">Cover</div>
                    </div>

                    <div className="min-w-0">
                        <Label htmlFor="cover-title" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                            Title
                        </Label>
                        <Input
                            id="cover-title"
                            value={coverTitle}
                            onChange={e => onCoverTitleChange(e.target.value)}
                            placeholder="Cover title"
                        />
                    </div>

                    <div className="min-w-0">
                        <Label htmlFor="cover-subtitle" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                            Subheadline
                        </Label>
                        <Input
                            id="cover-subtitle"
                            value={coverSubtitle}
                            onChange={e => onCoverSubtitleChange(e.target.value)}
                            placeholder="Optional"
                        />
                    </div>

                    <div className="min-w-0">
                        <Label htmlFor="cover-date" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                            Date
                        </Label>
                        <Input
                            id="cover-date"
                            value={coverDateText}
                            onChange={e => onCoverDateTextChange(e.target.value)}
                            placeholder="e.g. Summer 2025"
                            disabled={!coverShowDate}
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-3 xl:col-span-full">
                        <label className="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-neutral-50 px-4 py-3">
                            <Checkbox
                                checked={coverShowDate}
                                onCheckedChange={(checked) => onCoverShowDateChange(checked === true)}
                            />
                            <span className="text-sm font-medium text-neutral-700">Show date</span>
                        </label>

                        {coverWebSrc ? <img src={coverWebSrc} alt="cover" className="h-12 rounded-2xl border border-neutral-200" /> : null}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
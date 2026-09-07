export type TimetableSummary = {
    id: string;
    name: string;
    period: string;
    year: string;
    version: {
        id: string;
        number: number;
        status: string;
        entries: number;
    } | null;
};

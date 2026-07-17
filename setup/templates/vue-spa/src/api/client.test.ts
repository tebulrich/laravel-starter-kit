import { describe, expect, it } from 'vitest';
import { apiGet } from '@/api/client';

describe('api client', () => {
    it('exports apiGet', () => {
        expect(typeof apiGet).toBe('function');
    });
});

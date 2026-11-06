/** Stable builder API v1 (SW Forms 0.4.0). Add-ons share the host Vue runtime. */
import * as Vue from 'vue';
import type { Component } from 'vue';

export interface FieldUI { inspector?: Component; canvas?: Component }
export interface BuilderAPI {
  apiVersion: 1;
  vue: typeof Vue;
  registerFieldType(type: string, components: FieldUI): void;
}
export interface BuilderLoader {
  apiVersion: 1;
  register(callback: (api: BuilderAPI) => void): void;
  initialize(api: BuilderAPI): void;
}
declare global { interface Window { swFormsBuilder?: BuilderLoader } }

const fields = Vue.shallowReactive(new Map<string, FieldUI>());
export function registerFieldType(type: string, components: FieldUI): void {
  if (!/^[a-z][a-z0-9-]{0,39}$/.test(type)) return;
  fields.set(type, { ...components });
}
export function fieldUI(type: string): FieldUI | undefined { return fields.get(type); }
export function initializeBuilderAPI(): void {
  window.swFormsBuilder?.initialize({ apiVersion: 1, vue: Vue, registerFieldType });
}

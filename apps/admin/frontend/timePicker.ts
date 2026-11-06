type Unit = 'hour' | 'minute' | 'second';
type Slots = Map<string, Map<string, string[]>>;
const units: Unit[] = ['hour', 'minute', 'second'];
const pad = (value: number) => String(value).padStart(2, '0');
const seconds = (value: string) => {
  const [hours = 0, minutes = 0, remainder = 0] = value.split(':').map(Number);
  return hours * 3600 + minutes * 60 + remainder;
};

/** Bounded DOM: at most 24 hours and 60 minutes/seconds, even with a one-second step. */
class TimePicker {
  private readonly slots: Slots = new Map();
  private readonly group = document.createElement('div');
  private readonly controls = new Map<Unit, HTMLSelectElement>();
  private readonly events = new AbortController();
  private readonly label: HTMLLabelElement | null;

  constructor(private readonly input: HTMLInputElement) {
    const step = Math.max(1, Math.min(86400, Number(input.step) || 60));
    const start = input.min ? seconds(input.min) : 0;
    const end = input.max ? seconds(input.max) : 86399;
    for (let value = start; value <= end; value += step) {
      const hour = pad(Math.floor(value / 3600)); const minute = pad(Math.floor(value % 3600 / 60)); const second = pad(value % 60);
      const minutes = this.slots.get(hour) ?? new Map<string, string[]>();
      const values = minutes.get(minute) ?? []; values.push(second); minutes.set(minute, values); this.slots.set(hour, minutes);
    }
    const labels = JSON.parse(input.dataset.swfTimeLabels ?? '{}') as Record<Unit, string>;
    this.group.className = 'swf-time-picker'; this.group.setAttribute('role', 'group');
    this.label = input.parentElement?.querySelector('label') ?? null;
    if (this.label) { this.label.id ||= `${input.id}-label`; this.group.setAttribute('aria-labelledby', this.label.id); this.label.htmlFor = `${input.id}-hour`; }
    for (const unit of units) {
      const label = document.createElement('label'); label.textContent = labels[unit] ?? '';
      const select = document.createElement('select'); select.className = 'swf-select'; select.dataset.swfTimeUnit = unit; select.id = `${input.id}-${unit}`;
      select.addEventListener('change', () => this.choose(unit), { signal: this.events.signal });
      this.controls.set(unit, select); label.appendChild(select); this.group.appendChild(label);
      if (unit === 'second' && step % 60 === 0 && start % 60 === 0) label.hidden = true;
    }
    input.hidden = true; input.after(this.group); this.update();
  }

  private options(unit: Unit, values: string[], selected: string): void {
    const control = this.controls.get(unit)!;
    if (Array.from(control.options).map((option) => option.value).join(',') !== ['', ...values].join(',')) {
      control.replaceChildren(new Option('—', ''), ...values.map((value) => new Option(value, value)));
    }
    control.value = values.includes(selected) ? selected : '';
  }

  private choose(unit: Unit): void {
    const hour = this.controls.get('hour')!.value;
    const minute = this.controls.get('minute')!.value;
    const second = this.controls.get('second')!.value;
    if (this.controls.get(unit)!.value === '') this.input.value = '';
    else {
      const minutes = this.slots.get(hour);
      const chosenMinute = minutes?.has(minute) ? minute : minutes?.keys().next().value ?? '';
      const values = minutes?.get(chosenMinute) ?? [];
      const chosenSecond = values.includes(second) ? second : values[0];
      this.input.value = chosenSecond === undefined ? '' : `${hour}:${chosenMinute}${chosenSecond === '00' ? '' : ':' + chosenSecond}`;
    }
    this.update(); this.input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  update(): void {
    const [hour = '', minute = '', second = '00'] = this.input.value.split(':');
    const valid = this.slots.get(hour)?.get(minute)?.includes(second) ?? false;
    this.options('hour', [...this.slots.keys()], valid ? hour : '');
    this.options('minute', [...(this.slots.get(valid ? hour : '')?.keys() ?? [])], valid ? minute : '');
    this.options('second', this.slots.get(hour)?.get(valid ? minute : '') ?? [], valid ? second : '');
    for (const control of this.controls.values()) {
      control.disabled = this.input.disabled;
      for (const name of ['aria-describedby', 'aria-invalid', 'aria-required']) {
        const value = this.input.getAttribute(name);
        if (value === null) control.removeAttribute(name); else control.setAttribute(name, value);
      }
    }
  }

  destroy(): void { this.events.abort(); this.group.remove(); this.input.hidden = false; if (this.label) this.label.htmlFor = this.input.id; }
}

export class TimePickers {
  private readonly controls = new Map<HTMLInputElement, TimePicker>();
  constructor(private readonly form: HTMLFormElement) {}
  sync(): void {
    for (const [input, picker] of this.controls) {
      if (!this.form.contains(input)) { picker.destroy(); this.controls.delete(input); }
    }
    this.form.querySelectorAll<HTMLInputElement>('input[type=time][data-swf-time-picker]').forEach((input) => {
      if (!this.controls.has(input)) this.controls.set(input, new TimePicker(input));
      this.controls.get(input)!.update();
    });
  }
  destroy(): void { for (const picker of this.controls.values()) picker.destroy(); this.controls.clear(); }
}

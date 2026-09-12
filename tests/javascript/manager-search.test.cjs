const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function fixture() {
    let definition;
    const requests = [];
    const controls = {};
    const children = [];
    class Element {
        constructor(tag) { this.tag = tag; this.dataset = {}; this.events = {}; this.children = []; this.value = ''; }
        setAttribute(name, value) { this[name] = value; }
        appendChild(child) { this.children.push(child); }
        addEventListener(name, handler) { this.events[name] = handler; }
    }
    function control(options) {
        return {options, element: new Element('button'), enable() { this.enabled = true; },
            disable() { this.enabled = false; }, getElm() { return this.element; }};
    }
    const dependencies = {
        'qui/controls/buttons/Select': function (options) { return control(options); },
        Ajax: {get(name, resolve, options) { requests.push({name, resolve, options}); }},
        Locale: {get(group, key) { return key; }}
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/Manager.js'), 'utf8'), {
        define(name, deps, factory) { definition = factory(...deps.map(dep => dependencies[dep] || {})); },
        Class: function (value) { return value; },
        document: {createElement(tag) { return new Element(tag); }},
        JSON: {encode: JSON.stringify}
    });
    const panel = Object.assign({}, definition, {
        $project: 'first', $lang: 'en', $search: '', $loadRequest: 0,
        addButton(value) {
            const item = value instanceof Element || value.options ? value : control(value);
            children.push(item);
            if (item.options?.name) controls[item.options.name] = item;
        },
        getButtons(name) { return controls[name]; },
        getButtonBar() { return {getElm() { return {classList: {add() {}}}; }}; },
        Loader: {visible: false, show() { this.visible = true; }, hide() { this.visible = false; }},
        $Grid: {
            options: {page: 4, perPage: 20, sortOn: 'title', sortBy: 'ASC'},
            getAttribute(name) { return this.options[name]; },
            setData(data) { this.data = data; }
        }
    });
    panel.$onCreate();
    return {panel, requests, controls, children};
}

test('toolbar has exactly the requested order, separators and accessible delete icon', () => {
    const {children, controls} = fixture();
    assert.deepEqual(children.map(item => item.options?.name || item.options?.type || item.tag),
        ['tag-projects', 'separator', 'add-tag', 'showtagsites', 'form', 'separator', 'delete-tag']);
    assert.equal(controls['delete-tag'].options.text, undefined);
    assert.equal(controls['delete-tag'].element['aria-label'], 'panel.manager.button.delete.tag');
});

test('search starts on page one and sends project, language and trimmed term', async () => {
    const {panel, requests} = fixture();
    panel.$SearchInput.value = ' Needle ';
    const promise = panel.search();
    assert.equal(requests[0].options.projectName, 'first');
    assert.equal(requests[0].options.projectLang, 'en');
    assert.deepEqual(JSON.parse(requests[0].options.gridParams), {
        perPage: 20, page: 1, sortOn: 'title', sortBy: 'ASC', search: 'Needle'
    });
    requests[0].resolve({data: [{tag: 'found'}], total: 1});
    await promise;
    assert.equal(panel.$Grid.data.total, 1);
    assert.equal(panel.Loader.visible, false);
});

test('form submission triggers search and clearing the input restores the full list', async () => {
    const {panel, requests, children} = fixture();
    let prevented = false;
    panel.$SearchInput.value = 'term';
    children[4].events.submit({preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(JSON.parse(requests[0].options.gridParams).search, 'term');
    requests[0].resolve({data: [], total: 0});
    panel.$SearchInput.value = '';
    panel.$SearchInput.events.input();
    assert.equal(JSON.parse(requests[1].options.gridParams).search, '');
    requests[1].resolve({data: [], total: 0});
});

test('pagination keeps the search; switching projects resets the page', async () => {
    const {panel, requests} = fixture();
    panel.$search = 'term';
    const page = panel.refresh();
    assert.equal(JSON.parse(requests[0].options.gridParams).page, 4);
    requests[0].resolve({data: [], total: 0});
    await page;
    const switched = panel.loadProject('second', 'de');
    assert.equal(JSON.parse(requests[1].options.gridParams).page, 1);
    assert.equal(JSON.parse(requests[1].options.gridParams).search, 'term');
    assert.equal(requests[1].options.projectName, 'second');
    requests[1].resolve({data: [], total: 0});
    await switched;
});

test('a slower previous request cannot overwrite newer search results', async () => {
    const {panel, requests} = fixture();
    const first = panel.refresh();
    panel.$SearchInput.value = 'new';
    const latest = panel.search();
    requests[1].resolve({data: [{tag: 'new'}], total: 1});
    await latest;
    requests[0].resolve({data: [{tag: 'old'}], total: 1});
    await first;
    assert.equal(panel.$Grid.data.data[0].tag, 'new');
});

test('search still loads results when the site-count column is selected', async () => {
    const {panel, requests} = fixture();
    panel.$Grid.options.sortOn = 'count';
    panel.$Grid.options.sortBy = 'DESC';
    const result = panel.search();
    assert.equal(requests.length, 1);
    requests[0].resolve({data: [{count: 1}, {count: 12}], total: 2});
    await result;
    assert.equal(panel.$Grid.data.data[0].count, 12);
});

test('failed requests release the loading indicator', async () => {
    const {panel, requests} = fixture();
    const result = panel.refresh();
    requests[0].options.onError(new Error('Unavailable'));
    await assert.rejects(result, /Unavailable/);
    assert.equal(panel.Loader.visible, false);
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function importSettings(dataset) {
    let definition;
    const Projects = {get: (name, lang) => ({getName: () => name, getLang: () => lang})};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/tags/Select.js'), 'utf8'), {
        define(name, deps, factory) {
            definition = factory(...deps.map(dep => dep === 'Projects' ? Projects : {}));
        },
        Class: function (value) { return value; },
        typeOf: () => 'classes/projects/Project'
    });

    const options = {...definition.options};
    let imported;
    const control = {
        ...definition,
        getElm: () => ({dataset, form: {dataset: {projectName: 'shop', projectLang: 'de'}}}),
        getAttribute: name => options[name],
        setAttribute: (name, value) => { options[name] = value; },
        parent: () => { imported = {...options}; }
    };
    control.$onImport();
    return imported;
}

test('filter selection imports stored tags in the form project without a site limit', () => {
    const options = importSettings({considerMaxAmount: '0'});
    assert.equal(options.projectName, 'shop');
    assert.equal(options.projectLang, 'de');
    assert.equal(options.considerMaxAmount, false);
});

test('ordinary site tag selection still uses the site limit', () => {
    assert.equal(importSettings({}).considerMaxAmount, true);
});

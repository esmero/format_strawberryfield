# Format Strawberryfield REST OAI PMH (format_strawberryfield_rest_oai_pmh)

This format_strawberryfield submodule provides the ability to use Archipelago metadatadisplay templates to transform object metadata into OAI xml that can be served to OAI harvesters by way of the [rest_oai_php](https://www.drupal.org/project/rest_oai_pmh) module.

This is part of the Archipelago Commons Project.

## How to install, configure, and use

This module depends on having the [rest_oai_php](https://www.drupal.org/project/rest_oai_pmh) module installed. _Note that at this time there is a bugfix that is on the dev branch that this module depends on. Use `composer require drupal/rest_oai_pmh:2.0.x-dev`_

The steps to getting this module configured are:
1. Enable this module and it's dependencies (format_strawberryfield, rest_oai_pmh).
2. Create a view with an entity reference display that selects the ADOs that you wish to be harvested through OAI. Consult rest_oai_pmh module instructions for details.
3. Create metadatadisplay templates for MODS and/or Dublin Core that transform the native strawberryfield json into valid xml metadata that you wish to be harvested.
4. Go to "Administration > Configuration > Web services > Rest > REST OAI-PMH Settings > Templates" and select the metadatadisplay templates created in the previous step for MODS and/or DC.
5. At "Administration > Configuration > Web services > Rest > REST OAI-PMH Settings", under "What to expose to OAI-PMH", select the entity reference view you created earlier. In the "Metadata Mappings", select "MODS Metadatadisplay Template" and/or "Dublin Core Metadatadisplay Template".

### Configuration Details
#### Creating metadatadisplay templates
The metatadatadisplay templates that you use for MODS or Dublin Core xml export may work for your OAI harvest requirements. However the template that you use for OAI harvesting should not include the wrapper elements that would be provided for stand-alone MODS or DC xml. It should output raw xml that will inserted into the OAI feed under each record's `<metadata>` tag.  In other words, for MODS, it would **NOT** include...
```xml
<?xml version="1.0" encoding="UTF-8"?>
<mods:mods version="3.7" xmlns:mods="http://www.loc.gov/mods/v3"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xsi:schemaLocation="http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-7.xsd">
```
#### Wrapper elements
Namespace and other OAI wrapper elements can be added/edited on the "Administration > Configuration > Web services > Rest > REST OAI-PMH Settings > Templates" page. The default values may not need to be modified at all. But if they are edited, this text field must contain valid JSON, with each attribute in the form:

```"@attribute-name": "attribute value"```


## Help

Having issues with this module? Check out the Archipelago Commons google groups for tech + emotional support + updates.

* [Archipelago Commons](https://groups.google.com/forum/#!forum/archipelago-commons)

## Demo

* archipelago.nyc (http://archipelago.nyc)

## Caring & Coding + Fixing

* [Diego Pino](https://github.com/DiegoPino)
* [Pat Dunlavey](https://github.com/patdunlavey)

## Acknowledgments

This software is a [Metropolitan New York Library Council](https://metro.org) Open-Source initiative and part of the Archipelago Commons project.

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)

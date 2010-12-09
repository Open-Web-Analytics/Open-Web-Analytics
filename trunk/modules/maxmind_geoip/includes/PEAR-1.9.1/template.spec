Summary: PEAR: @summary@
Name: @rpm_package@
Version: @version@
Release: 1
License: @release_license@
Group: Development/Libraries
Source: http://@master_server@/get/@package@-%{version}.tgz
BuildRoot: %{_tmppath}/%{name}-root
URL: http://@master_server@/package/@package@
Prefix: %{_prefix}
BuildArchitectures: @arch@
@extra_headers@

%description
@description@

%prep
rm -rf %{buildroot}/*
%setup -c -T
# XXX Source files location is missing here in pear cmd
pear -v -c %{buildroot}/pearrc \
        -d php_dir=%{_libdir}/php/pear \
        -d doc_dir=/docs \
        -d bin_dir=%{_bindir} \
        -d data_dir=%{_libdir}/php/pear/data \
        -d test_dir=%{_libdir}/php/pear/tests \
        -d ext_dir=%{_libdir} \@extra_config@
        -s

%build
echo BuildRoot=%{buildroot}

%postun
# if refcount = 0 then package has been removed (not upgraded)
if [ "$1" -eq "0" ]; then
    pear uninstall --nodeps -r @possible_channel@@package@
    rm @rpm_xml_dir@/@package@.xml
fi


%post
# if refcount = 2 then package has been upgraded
if [ "$1" -ge "2" ]; then
    pear upgrade --nodeps -r @rpm_xml_dir@/@package@.xml
else
    pear install --nodeps -r @rpm_xml_dir@/@package@.xml
fi

%install
pear -c %{buildroot}/pearrc install --nodeps -R %{buildroot} \
        $RPM_SOURCE_DIR/@package@-%{version}.tgz
rm %{buildroot}/pearrc
rm %{buildroot}/%{_libdir}/php/pear/.filemap
rm %{buildroot}/%{_libdir}/php/pear/.lock
rm -rf %{buildroot}/%{_libdir}/php/pear/.registry
if [ "@doc_files@" != "" ]; then
     mv %{buildroot}/docs/@package@/* .
     rm -rf %{buildroot}/docs
fi
mkdir -p %{buildroot}@rpm_xml_dir@
tar -xzf $RPM_SOURCE_DIR/@package@-%{version}.tgz package@package2xml@.xml
cp -p package@package2xml@.xml %{buildroot}@rpm_xml_dir@/@package@.xml

#rm -rf %{buildroot}/*
#pear -q install -R %{buildroot} -n package@package2xml@.xml
#mkdir -p %{buildroot}@rpm_xml_dir@
#cp -p package@package2xml@.xml %{buildroot}@rpm_xml_dir@/@package@.xml

%files
    %defattr(-,root,root)
    %doc @doc_files@
    /
